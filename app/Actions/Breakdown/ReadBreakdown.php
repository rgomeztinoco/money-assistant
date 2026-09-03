<?php

namespace App\Actions\Breakdown;

use App\Actions\Reporting\NetSpendingAllocation;
use App\Actions\Reporting\ReadPeriodSummary;
use App\Currency;
use App\DataSources\ReadRecordedCoverage;
use App\ExactInteger;
use App\IncomeSource;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class ReadBreakdown
{
    public function __construct(
        private ReadPeriodSummary $readPeriodSummary,
        private MerchantNormalizer $merchantNormalizer,
        private NetSpendingAllocation $netSpendingAllocation,
        private ReadRecordedCoverage $readRecordedCoverage,
    ) {}

    /**
     * @param  array{currency?: string, period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string, category?: string, day?: string, focus?: string, merchant?: string, attention?: bool, selected?: int}  $filters
     * @return array<string, mixed>
     */
    public function handle(User $owner, array $filters): array
    {
        $currencyFilter = isset($filters['currency'])
            ? Currency::from($filters['currency'])
            : null;
        [$periodUnit, $dateFrom, $dateTo] = $this->period($filters);
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->select(['id', 'user_id', 'parent_id', 'name', 'archived_at'])
            ->orderByRaw('lower(name)')
            ->get();
        $categoriesById = $categories->keyBy('id');
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->when($currencyFilter !== null, fn ($query) => $query->where('currency', $currencyFilter))
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->select([
                'id',
                'user_id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'direction',
                'income_source',
                'transfer_purpose',
                'description',
                'instrument_label',
                'instrument_last_four',
                'confirmed_at',
                'category_id',
                'original_spending_id',
            ])
            ->with([
                'category:id,parent_id,name',
                'receiptBreakdown:id,transaction_id',
                'receiptBreakdown.lineItems:id,line_item_id,receipt_breakdown_id,category_id,description,line_total_minor',
                'receiptBreakdown.lineItems.category:id,parent_id,name',
                'statementMovement:id,transaction_id,statement_import_id',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
        $categoryFilter = $filters['category'] ?? null;
        $dayFilter = $filters['day'] ?? null;
        $focusFilter = $filters['focus'] ?? null;
        $merchantFilter = $filters['merchant'] ?? null;
        $attentionFilter = (bool) ($filters['attention'] ?? false);
        $attentionIds = $attentionFilter
            ? Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->when($currencyFilter !== null, fn ($query) => $query->where('currency', $currencyFilter))
                ->whereNull('voided_at')
                ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
                ->whereRequiresReview()
                ->pluck('id')
            : collect();
        $chartTransactions = $dayFilter === null
            ? $transactions
            : $transactions->where('occurred_on', CarbonImmutable::parse($dayFilter));
        $dailyTransactions = $categoryFilter === null
            ? $transactions
            : $transactions->filter(fn (Transaction $transaction): bool => $this->matchesCategory(
                $transaction,
                $categoryFilter,
                $categoriesById,
            ));
        $merchantTransactions = $transactions
            ->when($dayFilter !== null, fn (Collection $transactions): Collection => $transactions
                ->filter(fn (Transaction $transaction): bool => $transaction->occurred_on->toDateString() === $dayFilter))
            ->when($categoryFilter !== null, fn (Collection $transactions): Collection => $transactions
                ->filter(fn (Transaction $transaction): bool => $this->matchesCategory(
                    $transaction,
                    $categoryFilter,
                    $categoriesById,
                )))
            ->when($focusFilter !== null, fn (Collection $transactions): Collection => $transactions
                ->filter(fn (Transaction $transaction): bool => $this->matchesFocus($transaction, $focusFilter)))
            ->when($attentionFilter, fn (Collection $transactions): Collection => $transactions->whereIn('id', $attentionIds));
        $detailTransactions = $merchantTransactions
            ->when($merchantFilter !== null, fn (Collection $transactions): Collection => $transactions
                ->filter(fn (Transaction $transaction): bool => $this->merchantNormalizer->normalize($transaction->description)
                    === $this->merchantNormalizer->normalize($merchantFilter)));
        $coverageDates = $transactions->pluck('occurred_on');
        $merchantMatchCounts = $this->merchantMatchCounts($owner);
        $chartGranularity = $this->chartGranularity($periodUnit, $dateFrom, $dateTo);

        return [
            'currency_filter' => $currencyFilter?->value,
            'period' => [
                'unit' => $periodUnit,
                'label' => $this->periodLabel($periodUnit, $dateFrom, $dateTo),
                'anchor' => $this->periodAnchor($periodUnit, $dateFrom),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'coverage' => [
                'date_from' => $coverageDates->min()?->toDateString(),
                'date_to' => $coverageDates->max()?->toDateString(),
                'transaction_count' => $transactions->count(),
                'source' => $this->readRecordedCoverage->handle($owner, $dateFrom, $dateTo),
            ],
            'summary' => $this->summaries($owner, $dateFrom, $dateTo),
            'categorization' => $this->categorizationSummary($transactions, $categoriesById),
            'filters' => [
                'category' => $categoryFilter,
                'day' => $dayFilter,
                'focus' => $focusFilter,
                'merchant' => $merchantFilter,
                'attention' => $attentionFilter,
                'selected' => isset($filters['selected'])
                    ? (int) $filters['selected']
                    : null,
            ],
            'category_groups' => $this->categoryGroupsByCurrency($chartTransactions, $categories, $categoriesById),
            'chart_granularity' => $chartGranularity,
            'days' => $this->days(
                $dailyTransactions,
                $dateFrom,
                $dateTo,
                $chartGranularity,
            ),
            'merchants' => $this->merchants($merchantTransactions),
            'transaction_days' => $this->transactionDays($detailTransactions, $merchantMatchCounts),
            'category_options' => $this->categoryOptions($categories),
            'income_source_options' => $this->incomeSourceOptions($owner),
            'today' => now()->toDateString(),
        ];
    }

    private function matchesFocus(Transaction $transaction, string $focus): bool
    {
        return match ($focus) {
            'net_spending' => in_array($transaction->kind, [TransactionKind::Spending, TransactionKind::Refund], true),
            'income' => $transaction->kind === TransactionKind::Income,
            'savings' => $transaction->kind === TransactionKind::Transfer
                && $transaction->transfer_purpose === TransferPurpose::Savings,
            default => false,
        };
    }

    /**
     * @param  array{period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string}  $filters
     * @return array{string, CarbonImmutable, CarbonImmutable}
     */
    private function period(array $filters): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $periodUnit = $filters['period'] ?? null;
        $anchor = CarbonImmutable::parse($filters['anchor'] ?? $today, config('app.timezone'));
        $preset = $filters['preset'] ?? null;

        if (($periodUnit === 'custom' || $preset === 'custom')
            && isset($filters['date_from'], $filters['date_to'])) {
            return [
                'custom',
                CarbonImmutable::parse($filters['date_from'], config('app.timezone')),
                CarbonImmutable::parse($filters['date_to'], config('app.timezone')),
            ];
        }

        if ($periodUnit === 'week') {
            return ['week', $anchor->startOfWeek(), $anchor->endOfWeek()];
        }

        if ($periodUnit === 'quarter') {
            return ['quarter', $anchor->startOfQuarter(), $anchor->endOfQuarter()];
        }

        if ($periodUnit === 'year') {
            return ['year', $anchor->startOfYear(), $anchor->endOfYear()];
        }

        if ($periodUnit === 'month') {
            return ['month', $anchor->startOfMonth(), $anchor->endOfMonth()];
        }

        if ($preset === 'last_month') {
            $month = $today->subMonthNoOverflow();

            return ['month', $month->startOfMonth(), $month->endOfMonth()];
        }

        if ($preset === 'rolling_30') {
            return ['custom', $today->subDays(29), $today];
        }

        if ($preset === 'this_month') {
            return ['month', $today->startOfMonth(), $today->endOfMonth()];
        }

        return ['month', $today->startOfMonth(), $today->endOfMonth()];
    }

    /** @return array<string, array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string}> */
    private function summaries(User $owner, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        return collect(Currency::cases())
            ->mapWithKeys(fn (Currency $currency): array => [
                $currency->value => $this->readPeriodSummary->handle($owner, $currency, $dateFrom, $dateTo),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categoriesById
     * @return array<string, array{transaction_count: int, uncategorized_transaction_count: int, uncategorized_amount_minor: string, uncategorized_percentage: string}>
     */
    private function categorizationSummary(Collection $transactions, Collection $categoriesById): array
    {
        $summary = [];

        foreach (Currency::cases() as $currency) {
            $currencyTransactions = $transactions
                ->where('currency', $currency)
                ->filter(fn (Transaction $transaction): bool => $transaction->kind->supportsCategory());
            $totalAmount = ExactInteger::from(0);
            $uncategorizedAmount = ExactInteger::from(0);
            $uncategorizedTransactionCount = 0;

            foreach ($currencyTransactions as $transaction) {
                $totalAmount = $totalAmount->add($this->absolute($transaction->amount_minor));
                $allocation = $this->netSpendingAllocation->byCategory($transaction, $categoriesById);
                $uncategorizedAllocation = $allocation['uncategorized'] ?? ExactInteger::from(0);

                if ($uncategorizedAllocation->compare(ExactInteger::from(0)) !== 0) {
                    $uncategorizedTransactionCount++;
                    $uncategorizedAmount = $uncategorizedAmount->add(
                        $this->absolute($uncategorizedAllocation->value()),
                    );
                }
            }

            $summary[$currency->value] = [
                'transaction_count' => $currencyTransactions->count(),
                'uncategorized_transaction_count' => $uncategorizedTransactionCount,
                'uncategorized_amount_minor' => $uncategorizedAmount->value(),
                'uncategorized_percentage' => $this->percentage($uncategorizedAmount, $totalAmount),
            ];
        }

        return $summary;
    }

    private function periodAnchor(string $periodUnit, CarbonImmutable $dateFrom): string
    {
        return match ($periodUnit) {
            'week' => $dateFrom->startOfWeek()->toDateString(),
            'month' => $dateFrom->startOfMonth()->toDateString(),
            'quarter' => $dateFrom->startOfQuarter()->toDateString(),
            'year' => $dateFrom->startOfYear()->toDateString(),
            default => $dateFrom->toDateString(),
        };
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, Category>  $categoriesById
     * @return list<array<string, mixed>>
     */
    private function categoryGroups(Collection $transactions, Collection $categories, Collection $categoriesById): array
    {
        /** @var array<int|string, ExactInteger> $amounts */
        $amounts = [];

        foreach ($transactions as $transaction) {
            if (! $transaction->kind->supportsCategory()) {
                continue;
            }

            foreach ($this->netSpendingAllocation->byCategory($transaction, $categoriesById) as $categoryKey => $amount) {
                $amounts[$categoryKey] = ($amounts[$categoryKey] ?? ExactInteger::from(0))->add($amount);
            }
        }

        $totalSpending = $transactions
            ->where('kind', TransactionKind::Spending)
            ->reduce(
                fn (ExactInteger $total, Transaction $transaction): ExactInteger => $total->add(
                    ExactInteger::from($transaction->amount_minor),
                ),
                ExactInteger::from(0),
            );
        $groups = [];

        foreach ($categories->whereNull('parent_id') as $category) {
            $amount = $amounts[$category->id] ?? null;

            if ($amount === null || $amount->compare(ExactInteger::from(0)) === 0) {
                continue;
            }

            $children = [];

            foreach ($categories->where('parent_id', $category->id) as $child) {
                $childAmount = $amounts[$child->id] ?? null;

                if ($childAmount === null || $childAmount->compare(ExactInteger::from(0)) === 0) {
                    continue;
                }

                $children[] = [
                    'category' => ['id' => $child->id, 'name' => $child->name],
                    'amount_minor' => $childAmount->value(),
                ];
            }

            $groups[] = [
                'category' => ['id' => $category->id, 'name' => $category->name],
                'amount_minor' => $amount->value(),
                'percentage' => $this->percentage($amount, $totalSpending),
                'children' => $children,
            ];
        }

        $uncategorized = $amounts['uncategorized'] ?? null;

        if ($uncategorized !== null && $uncategorized->compare(ExactInteger::from(0)) !== 0) {
            $groups[] = [
                'category' => ['id' => null, 'name' => 'Uncategorized'],
                'amount_minor' => $uncategorized->value(),
                'percentage' => $this->percentage($uncategorized, $totalSpending),
                'children' => [],
            ];
        }

        usort($groups, fn (array $left, array $right): int => $this->absolute($right['amount_minor'])
            ->compare($this->absolute($left['amount_minor'])));

        return $groups;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, Category>  $categoriesById
     * @return list<array<string, mixed>>
     */
    private function categoryGroupsByCurrency(Collection $transactions, Collection $categories, Collection $categoriesById): array
    {
        $groupsByCategory = [];

        foreach (Currency::cases() as $currency) {
            $currencyGroups = $this->categoryGroups(
                $transactions->where('currency', $currency),
                $categories,
                $categoriesById,
            );

            foreach ($currencyGroups as $group) {
                $key = $group['category']['id'] ?? 'uncategorized';
                $groupsByCategory[$key] ??= [
                    'category' => $group['category'],
                    'amount_minor' => $this->emptyCurrencyAmounts(),
                    'percentage' => $this->emptyCurrencyAmounts(),
                    'children' => [],
                ];
                $groupsByCategory[$key]['amount_minor'][$currency->value] = $group['amount_minor'];
                $groupsByCategory[$key]['percentage'][$currency->value] = $group['percentage'];

                foreach ($group['children'] as $child) {
                    $childId = $child['category']['id'];
                    $groupsByCategory[$key]['children'][$childId] ??= [
                        'category' => $child['category'],
                        'amount_minor' => $this->emptyCurrencyAmounts(),
                    ];
                    $groupsByCategory[$key]['children'][$childId]['amount_minor'][$currency->value] = $child['amount_minor'];
                }
            }
        }

        $groups = array_values(array_map(function (array $group): array {
            $group['children'] = array_values($group['children']);

            return $group;
        }, $groupsByCategory));
        usort($groups, function (array $left, array $right): int {
            $byPercentage = $this->largestPercentage($right['percentage'])
                <=> $this->largestPercentage($left['percentage']);

            return $byPercentage !== 0
                ? $byPercentage
                : strcasecmp($left['category']['name'], $right['category']['name']);
        });

        return $groups;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{date: string, date_to: string, net_spending_minor: array<string, string>, transaction_count: int}>
     */
    private function days(
        Collection $transactions,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        string $granularity,
    ): array {
        $days = [];

        for ($date = $dateFrom; $date->lessThanOrEqualTo($dateTo); $date = $this->nextChartBucket($date, $granularity)) {
            $bucketDateTo = $this->chartBucketEnd($date, $dateTo, $granularity);
            $dayTransactions = $transactions->filter(
                fn (Transaction $transaction): bool => $transaction->occurred_on->betweenIncluded($date, $bucketDateTo),
            );
            $netSpending = $this->emptyCurrencyTotals();

            foreach ($dayTransactions as $transaction) {
                $currency = $transaction->currency->value;
                $netSpending[$currency] = $netSpending[$currency]->add(
                    $transaction->kind->netSpendingAmount($transaction->amount_minor),
                );
            }

            $days[] = [
                'date' => $date->toDateString(),
                'date_to' => $bucketDateTo->toDateString(),
                'net_spending_minor' => $this->currencyTotalValues($netSpending),
                'transaction_count' => $dayTransactions->count(),
            ];
        }

        return $days;
    }

    private function chartGranularity(
        string $periodUnit,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): string {
        if ($periodUnit === 'year') {
            return 'month';
        }

        if ($periodUnit === 'quarter') {
            return 'week';
        }

        if ($periodUnit !== 'custom' || $dateFrom->diffInDays($dateTo) + 1 <= 31) {
            return 'day';
        }

        return $dateFrom->diffInDays($dateTo) + 1 <= 120 ? 'week' : 'month';
    }

    private function nextChartBucket(CarbonImmutable $date, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'month' => $date->addMonth(),
            'week' => $date->addWeek(),
            default => $date->addDay(),
        };
    }

    private function chartBucketEnd(
        CarbonImmutable $date,
        CarbonImmutable $periodDateTo,
        string $granularity,
    ): CarbonImmutable {
        $bucketDateTo = match ($granularity) {
            'month' => $date->endOfMonth(),
            'week' => $date->addDays(6),
            default => $date,
        };

        return $bucketDateTo->lessThan($periodDateTo) ? $bucketDateTo : $periodDateTo;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{name: string, amount_minor: array<string, string>, transaction_count: int}>
     */
    private function merchants(Collection $transactions): array
    {
        /** @var array<string, array{name: string, amount: array<string, ExactInteger>, transaction_count: int}> $merchants */
        $merchants = [];

        foreach ($transactions as $transaction) {
            if (! $transaction->kind->supportsCategory()) {
                continue;
            }

            $merchantKey = $this->merchantNormalizer->normalize($transaction->description);
            $merchants[$merchantKey] ??= [
                'name' => $transaction->description,
                'amount' => $this->emptyCurrencyTotals(),
                'transaction_count' => 0,
            ];
            $currency = $transaction->currency->value;
            $merchants[$merchantKey]['amount'][$currency] = $merchants[$merchantKey]['amount'][$currency]->add(
                $transaction->kind->netSpendingAmount($transaction->amount_minor),
            );
            $merchants[$merchantKey]['transaction_count']++;
        }

        $merchantRows = array_values(array_map(fn (array $merchant): array => [
            'name' => $merchant['name'],
            'amount_minor' => $this->currencyTotalValues($merchant['amount']),
            'transaction_count' => $merchant['transaction_count'],
        ], $merchants));
        usort($merchantRows, fn (array $left, array $right): int => $right['transaction_count']
            <=> $left['transaction_count']);

        return $merchantRows;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, int>  $merchantMatchCounts
     * @return list<array{date: string, net_spending_minor: array<string, string>, income_minor: array<string, string>, moved_to_savings_minor: array<string, string>, transactions: list<array<string, mixed>>}>
     */
    private function transactionDays(Collection $transactions, array $merchantMatchCounts): array
    {
        return array_values($transactions
            ->groupBy(fn (Transaction $transaction): string => $transaction->occurred_on->toDateString())
            ->map(function (Collection $dayTransactions, string $date) use ($merchantMatchCounts): array {
                $netSpending = $this->emptyCurrencyTotals();
                $income = $this->emptyCurrencyTotals();
                $movedToSavings = $this->emptyCurrencyTotals();

                foreach ($dayTransactions as $transaction) {
                    $amount = ExactInteger::from($transaction->amount_minor);
                    $currency = $transaction->currency->value;
                    $netSpending[$currency] = $netSpending[$currency]->add($transaction->kind->netSpendingAmount($transaction->amount_minor));

                    if ($transaction->kind === TransactionKind::Income) {
                        $income[$currency] = $income[$currency]->add($amount);
                    }

                    if ($transaction->kind === TransactionKind::Transfer && $transaction->transfer_purpose === TransferPurpose::Savings) {
                        $movedToSavings[$currency] = $transaction->direction === MovementDirection::Credit
                            ? $movedToSavings[$currency]->subtract($amount)
                            : $movedToSavings[$currency]->add($amount);
                    }
                }

                return [
                    'date' => $date,
                    'net_spending_minor' => $this->currencyTotalValues($netSpending),
                    'income_minor' => $this->currencyTotalValues($income),
                    'moved_to_savings_minor' => $this->currencyTotalValues($movedToSavings),
                    'transactions' => array_values($dayTransactions
                        ->map(fn (Transaction $transaction): array => $this->transactionData($transaction, $merchantMatchCounts))
                        ->all()),
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @param  array<string, int>  $merchantMatchCounts
     * @return array<string, mixed>
     */
    private function transactionData(Transaction $transaction, array $merchantMatchCounts): array
    {
        $merchantKey = $transaction->kind->supportsCategory()
            ? $this->merchantNormalizer->normalize($transaction->description)
            : null;

        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'direction' => $transaction->direction->value,
            'income_source' => $transaction->income_source?->value,
            'transfer_purpose' => $transaction->transfer_purpose?->value,
            'description' => $transaction->description,
            'category' => $transaction->category === null
                ? null
                : ['id' => $transaction->category->id, 'name' => $transaction->category->name],
            'original_spending_id' => $transaction->original_spending_id,
            'merchant_match_count' => $merchantKey === null
                ? 0
                : ($merchantMatchCounts[$this->merchantCountKey($merchantKey, $transaction)] ?? 0),
            'instrument_label' => $transaction->instrument_label,
            'instrument_last_four' => $transaction->instrument_last_four,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'statement_import_id' => $transaction->statementMovement?->statement_import_id,
            'split' => $transaction->receiptBreakdown === null
                ? null
                : array_values($transaction->receiptBreakdown->lineItems
                    ->map(fn ($lineItem): array => [
                        'id' => $lineItem->line_item_id,
                        'amount_minor' => (string) $lineItem->line_total_minor,
                        'category' => $lineItem->category === null
                            ? null
                            : ['id' => $lineItem->category->id, 'name' => $lineItem->category->name],
                    ])
                    ->all()),
        ];
    }

    /**
     * @param  Collection<int, Category>  $categoriesById
     */
    private function matchesCategory(Transaction $transaction, string $categoryFilter, Collection $categoriesById): bool
    {
        if (! $transaction->kind->supportsCategory()) {
            return false;
        }

        $lineItems = $transaction->receiptBreakdown?->lineItems;
        $contributionCategoryIds = $lineItems === null || $lineItems->isEmpty()
            ? [$transaction->category_id]
            : $lineItems->pluck('category_id')->all();

        if ($categoryFilter === 'uncategorized') {
            return in_array(null, $contributionCategoryIds, true);
        }

        $categoryId = (int) $categoryFilter;

        foreach ($contributionCategoryIds as $contributionCategoryId) {
            if ($contributionCategoryId === $categoryId) {
                return true;
            }

            if ($contributionCategoryId !== null && $categoriesById->get($contributionCategoryId)?->parent_id === $categoryId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    private function merchantMatchCounts(User $owner): array
    {
        $counts = [];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->cursor();

        foreach ($transactions as $transaction) {
            $merchantKey = $this->merchantNormalizer->normalize($transaction->description);
            $countKey = $this->merchantCountKey($merchantKey, $transaction);
            $counts[$countKey] = ($counts[$countKey] ?? 0) + 1;
        }

        return $counts;
    }

    private function merchantCountKey(string $merchantKey, Transaction $transaction): string
    {
        return $merchantKey.'|'.$transaction->kind->value.'|'.$transaction->currency->value;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<array{id: int, name: string, path: string, parent: array{id: int, name: string}|null}>
     */
    private function categoryOptions(Collection $categories): array
    {
        return array_values($categories
            ->whereNull('archived_at')
            ->map(function (Category $category) use ($categories): array {
                $parent = $category->parent_id === null ? null : $categories->firstWhere('id', $category->parent_id);

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'path' => $parent === null ? $category->name : $parent->name.' > '.$category->name,
                    'parent' => $parent === null
                        ? null
                        : ['id' => $parent->id, 'name' => $parent->name],
                ];
            })
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /** @return list<array{value: string, used: bool}> */
    private function incomeSourceOptions(User $owner): array
    {
        $usedSources = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('kind', TransactionKind::Income)
            ->whereNotNull('income_source')
            ->distinct()
            ->pluck('income_source')
            ->map(fn (IncomeSource $source): string => $source->value)
            ->all();
        $usedOptions = [];
        $unusedOptions = [];

        foreach (IncomeSource::cases() as $source) {
            $option = [
                'value' => $source->value,
                'used' => in_array($source->value, $usedSources, true),
            ];

            if ($option['used']) {
                $usedOptions[] = $option;
            } else {
                $unusedOptions[] = $option;
            }
        }

        return [...$usedOptions, ...$unusedOptions];
    }

    private function percentage(ExactInteger $amount, ExactInteger $total): string
    {
        if ($total->compare(ExactInteger::from(0)) === 0) {
            return '0';
        }

        $percentage = bcdiv(
            $amount->multiply(ExactInteger::from(100))->value(),
            $total->value(),
            4,
        );

        return rtrim(rtrim(bcround($percentage, 2), '0'), '.');
    }

    private function absolute(int|string $amount): ExactInteger
    {
        $amount = ExactInteger::from($amount);

        return $amount->compare(ExactInteger::from(0)) === -1
            ? ExactInteger::from(0)->subtract($amount)
            : $amount;
    }

    /** @return array<string, ExactInteger> */
    private function emptyCurrencyTotals(): array
    {
        return collect(Currency::cases())
            ->mapWithKeys(fn (Currency $currency): array => [$currency->value => ExactInteger::from(0)])
            ->all();
    }

    /** @return array<string, string> */
    private function emptyCurrencyAmounts(): array
    {
        return collect(Currency::cases())
            ->mapWithKeys(fn (Currency $currency): array => [$currency->value => '0'])
            ->all();
    }

    /**
     * @param  array<string, ExactInteger>  $totals
     * @return array<string, string>
     */
    private function currencyTotalValues(array $totals): array
    {
        return collect($totals)
            ->map(fn (ExactInteger $amount): string => $amount->value())
            ->all();
    }

    /** @param array<string, string> $percentages */
    private function largestPercentage(array $percentages): float
    {
        return collect($percentages)
            ->max(fn (string $percentage): float => abs((float) $percentage)) ?? 0;
    }

    private function periodLabel(string $periodUnit, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): string
    {
        return match ($periodUnit) {
            'month' => $dateFrom->isoFormat('MMMM YYYY'),
            'quarter' => 'Q'.$dateFrom->quarter.' '.$dateFrom->year,
            'year' => (string) $dateFrom->year,
            default => $dateFrom->isoFormat('ll').' – '.$dateTo->isoFormat('ll'),
        };
    }
}
