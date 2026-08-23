<?php

namespace App\Actions\Breakdown;

use App\Actions\Reporting\ReadPeriodSummary;
use App\Currency;
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
    ) {}

    /**
     * @param  array{preset?: string, date_from?: string, date_to?: string, category?: string, day?: string, selected?: int}  $filters
     * @return array<string, mixed>
     */
    public function handle(User $owner, Currency $currency, array $filters): array
    {
        [$preset, $dateFrom, $dateTo] = $this->period($owner, $currency, $filters);
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->select(['id', 'user_id', 'parent_id', 'name', 'archived_at'])
            ->withCount(['transactions', 'lineItems'])
            ->orderByRaw('lower(name)')
            ->get();
        $categoriesById = $categories->keyBy('id');
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
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
        $detailTransactions = $transactions
            ->when($dayFilter !== null, fn (Collection $transactions): Collection => $transactions
                ->filter(fn (Transaction $transaction): bool => $transaction->occurred_on->toDateString() === $dayFilter))
            ->when($categoryFilter !== null, fn (Collection $transactions): Collection => $transactions
                ->filter(fn (Transaction $transaction): bool => $this->matchesCategory(
                    $transaction,
                    $categoryFilter,
                    $categoriesById,
                )));
        $summary = $this->readPeriodSummary->handle($owner, $currency, $dateFrom, $dateTo);
        $coverageDates = $transactions->pluck('occurred_on');
        $merchantMatchCounts = $this->merchantMatchCounts($owner, $currency);

        return [
            'currency' => $currency->value,
            'period' => [
                'preset' => $preset,
                'label' => $this->periodLabel($dateFrom, $dateTo),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'coverage' => [
                'date_from' => $coverageDates->min()?->toDateString(),
                'date_to' => $coverageDates->max()?->toDateString(),
                'transaction_count' => $transactions->count(),
            ],
            'summary' => $summary,
            'filters' => [
                'category' => $categoryFilter,
                'day' => $dayFilter,
                'selected' => isset($filters['selected'])
                    ? (int) $filters['selected']
                    : null,
            ],
            'category_groups' => $this->categoryGroups($chartTransactions, $categories, $categoriesById),
            'days' => $this->days($dailyTransactions),
            'merchants' => $this->merchants($detailTransactions),
            'transaction_days' => $this->transactionDays($detailTransactions, $merchantMatchCounts),
            'category_options' => $this->categoryOptions($categories),
            'income_source_options' => $this->incomeSourceOptions($owner),
            'today' => now()->toDateString(),
        ];
    }

    /**
     * @param  array{preset?: string, date_from?: string, date_to?: string}  $filters
     * @return array{string, CarbonImmutable, CarbonImmutable}
     */
    private function period(User $owner, Currency $currency, array $filters): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $preset = $filters['preset'] ?? null;

        if ($preset === 'custom'
            && isset($filters['date_from'], $filters['date_to'])) {
            return [
                'custom',
                CarbonImmutable::parse($filters['date_from'], config('app.timezone')),
                CarbonImmutable::parse($filters['date_to'], config('app.timezone')),
            ];
        }

        if ($preset === 'last_month') {
            $month = $today->subMonthNoOverflow();

            return ['last_month', $month->startOfMonth(), $month->endOfMonth()];
        }

        if ($preset === 'rolling_30') {
            return ['rolling_30', $today->subDays(29), $today];
        }

        if ($preset === 'this_month') {
            return ['this_month', $today->startOfMonth(), $today];
        }

        $hasCurrentMonthData = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$today->startOfMonth()->toDateString(), $today->toDateString()])
            ->exists();

        if ($hasCurrentMonthData) {
            return ['this_month', $today->startOfMonth(), $today];
        }

        $latestOccurredOn = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->max('occurred_on');

        if (! is_string($latestOccurredOn)) {
            return ['this_month', $today->startOfMonth(), $today];
        }

        $latestMonth = CarbonImmutable::parse($latestOccurredOn, config('app.timezone'));

        return ['latest_month', $latestMonth->startOfMonth(), $latestMonth->endOfMonth()];
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

            $lineItems = $transaction->receiptBreakdown?->lineItems;

            if ($lineItems === null || $lineItems->isEmpty()) {
                $this->addCategoryAmount(
                    $amounts,
                    $transaction->category_id,
                    $transaction->kind->netSpendingAmount($transaction->amount_minor),
                    $categoriesById,
                );

                continue;
            }

            foreach ($lineItems as $lineItem) {
                $this->addCategoryAmount(
                    $amounts,
                    $lineItem->category_id,
                    $transaction->kind->netSpendingAmount($lineItem->line_total_minor),
                    $categoriesById,
                );
            }
        }

        $topLevelCategoryKeys = $categories
            ->whereNull('parent_id')
            ->pluck('id')
            ->push('uncategorized')
            ->all();
        $total = collect($amounts)
            ->only($topLevelCategoryKeys)
            ->reduce(fn (ExactInteger $total, ExactInteger $amount): ExactInteger => $total->add($amount), ExactInteger::from(0));
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
                'percentage' => $this->percentage($amount, $total),
                'children' => $children,
            ];
        }

        $uncategorized = $amounts['uncategorized'] ?? null;

        if ($uncategorized !== null && $uncategorized->compare(ExactInteger::from(0)) !== 0) {
            $groups[] = [
                'category' => ['id' => null, 'name' => 'Uncategorized'],
                'amount_minor' => $uncategorized->value(),
                'percentage' => $this->percentage($uncategorized, $total),
                'children' => [],
            ];
        }

        usort($groups, fn (array $left, array $right): int => $this->absolute($right['amount_minor'])
            ->compare($this->absolute($left['amount_minor'])));

        return $groups;
    }

    /**
     * @param  array<int|string, ExactInteger>  $amounts
     * @param  Collection<int, Category>  $categoriesById
     */
    private function addCategoryAmount(array &$amounts, ?int $categoryId, ExactInteger $amount, Collection $categoriesById): void
    {
        if ($categoryId === null || ! $categoriesById->has($categoryId)) {
            $amounts['uncategorized'] = ($amounts['uncategorized'] ?? ExactInteger::from(0))->add($amount);

            return;
        }

        $category = $categoriesById->get($categoryId);
        $amounts[$category->id] = ($amounts[$category->id] ?? ExactInteger::from(0))->add($amount);

        if ($category->parent_id !== null) {
            $amounts[$category->parent_id] = ($amounts[$category->parent_id] ?? ExactInteger::from(0))->add($amount);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{date: string, net_spending_minor: string, transaction_count: int}>
     */
    private function days(Collection $transactions): array
    {
        return array_values($transactions
            ->groupBy(fn (Transaction $transaction): string => $transaction->occurred_on->toDateString())
            ->sortKeys()
            ->map(function (Collection $dayTransactions, string $date): array {
                $netSpending = ExactInteger::from(0);

                foreach ($dayTransactions as $transaction) {
                    $netSpending = $netSpending->add(
                        $transaction->kind->netSpendingAmount($transaction->amount_minor),
                    );
                }

                return [
                    'date' => $date,
                    'net_spending_minor' => $netSpending->value(),
                    'transaction_count' => $dayTransactions->count(),
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{name: string, amount_minor: string, transaction_count: int}>
     */
    private function merchants(Collection $transactions): array
    {
        /** @var array<string, array{name: string, amount: ExactInteger, transaction_count: int}> $merchants */
        $merchants = [];

        foreach ($transactions as $transaction) {
            if (! $transaction->kind->supportsCategory()) {
                continue;
            }

            $merchantKey = $this->merchantNormalizer->normalize($transaction->description);
            $merchants[$merchantKey] ??= [
                'name' => $transaction->description,
                'amount' => ExactInteger::from(0),
                'transaction_count' => 0,
            ];
            $merchants[$merchantKey]['amount'] = $merchants[$merchantKey]['amount']->add(
                $transaction->kind->netSpendingAmount($transaction->amount_minor),
            );
            $merchants[$merchantKey]['transaction_count']++;
        }

        $merchantRows = array_values(array_map(fn (array $merchant): array => [
            'name' => $merchant['name'],
            'amount_minor' => $merchant['amount']->value(),
            'transaction_count' => $merchant['transaction_count'],
        ], $merchants));
        usort($merchantRows, fn (array $left, array $right): int => $this->absolute($right['amount_minor'])
            ->compare($this->absolute($left['amount_minor'])));

        return $merchantRows;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, int>  $merchantMatchCounts
     * @return list<array{date: string, net_spending_minor: string, income_minor: string, moved_to_savings_minor: string, transactions: list<array<string, mixed>>}>
     */
    private function transactionDays(Collection $transactions, array $merchantMatchCounts): array
    {
        return array_values($transactions
            ->groupBy(fn (Transaction $transaction): string => $transaction->occurred_on->toDateString())
            ->map(function (Collection $dayTransactions, string $date) use ($merchantMatchCounts): array {
                $netSpending = ExactInteger::from(0);
                $income = ExactInteger::from(0);
                $movedToSavings = ExactInteger::from(0);

                foreach ($dayTransactions as $transaction) {
                    $amount = ExactInteger::from($transaction->amount_minor);
                    $netSpending = $netSpending->add($transaction->kind->netSpendingAmount($transaction->amount_minor));

                    if ($transaction->kind === TransactionKind::Income) {
                        $income = $income->add($amount);
                    }

                    if ($transaction->kind === TransactionKind::Transfer && $transaction->transfer_purpose === TransferPurpose::Savings) {
                        $movedToSavings = $transaction->direction === MovementDirection::Credit
                            ? $movedToSavings->subtract($amount)
                            : $movedToSavings->add($amount);
                    }
                }

                return [
                    'date' => $date,
                    'net_spending_minor' => $netSpending->value(),
                    'income_minor' => $income->value(),
                    'moved_to_savings_minor' => $movedToSavings->value(),
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
    private function merchantMatchCounts(User $owner, Currency $currency): array
    {
        $counts = [];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
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
     * @return list<array{id: int, path: string, used: bool}>
     */
    private function categoryOptions(Collection $categories): array
    {
        return array_values($categories
            ->whereNull('archived_at')
            ->map(function (Category $category) use ($categories): array {
                $parent = $category->parent_id === null ? null : $categories->firstWhere('id', $category->parent_id);

                return [
                    'id' => $category->id,
                    'path' => $parent === null ? $category->name : $parent->name.' > '.$category->name,
                    'used' => $category->transactions_count > 0 || $category->line_items_count > 0,
                ];
            })
            ->sortBy([['used', 'desc'], ['path', 'asc']])
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

    private function periodLabel(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): string
    {
        return $dateFrom->isoFormat('ll').' – '.$dateTo->isoFormat('ll');
    }
}
