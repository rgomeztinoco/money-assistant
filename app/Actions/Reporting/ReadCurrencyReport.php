<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type ReportCategoryData array{id: int|null, name: string, archived: bool}
 * @phpstan-type ReportCategoryAmountData array{category: ReportCategoryData, amount_minor: string}
 * @phpstan-type ReportCategoryGroupData array{category: ReportCategoryData, amount_minor: string, children: list<ReportCategoryAmountData>}
 * @phpstan-type ReportMonthData array{month: string, label: string, date_from: string, date_to: string, total_minor: string, transaction_count: int}
 */
final class ReadCurrencyReport
{
    public function __construct(private ReadSpendingAnalysis $readSpendingAnalysis) {}

    /**
     * @return array{
     *     currency: string,
     *     period: array{label: string, date_from: string, date_to: string, total_minor: string},
     *     comparison: array{period: array{label: string, date_from: string, date_to: string}, current_total_minor: string, previous_total_minor: string, change_minor: string, percentage_change: string|null, direction: string},
     *     monthly_history: list<ReportMonthData>,
     *     category_groups: list<ReportCategoryGroupData>
     * }
     */
    public function handle(
        User $owner,
        Currency $currency,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): array {
        $comparisonPeriod = SpendingComparisonPeriod::preceding($dateFrom, $dateTo);
        $analysis = $this->readSpendingAnalysis->handle(
            owner: $owner,
            currency: $currency,
            period: $comparisonPeriod,
        );
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'archived_at']);
        $categoriesById = $categories->keyBy('id');
        $periodTotal = ExactInteger::from(0);

        /** @var array<int|string, ExactInteger> $categoryAmounts */
        $categoryAmounts = [];

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->select(['id', 'amount_minor', 'kind', 'category_id'])
            ->with([
                'receiptBreakdown:id,transaction_id',
                'receiptBreakdown.lineItems:id,receipt_breakdown_id,category_id,line_total_minor',
            ])
            ->lazyById();

        foreach ($transactions as $transaction) {
            $transactionAmount = $transaction->kind->signedAmount((string) $transaction->amount_minor);
            $periodTotal = $periodTotal->add($transactionAmount);
            $lineItems = $transaction->receiptBreakdown?->lineItems;

            if ($lineItems === null || $lineItems->isEmpty()) {
                $this->addCategoryAmount(
                    $categoryAmounts,
                    $transaction->category_id,
                    $transactionAmount,
                    $categoriesById,
                );

                continue;
            }

            foreach ($lineItems as $lineItem) {
                $this->addCategoryAmount(
                    $categoryAmounts,
                    $lineItem->category_id,
                    $transaction->kind->signedAmount($lineItem->line_total_minor),
                    $categoriesById,
                );
            }
        }

        return [
            'currency' => $currency->value,
            'period' => [
                'label' => $this->periodLabel($dateFrom, $dateTo),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_minor' => $periodTotal->value(),
            ],
            'comparison' => [
                'period' => [
                    'label' => $this->periodLabel($comparisonPeriod->previousDateFrom, $comparisonPeriod->previousDateTo),
                    'date_from' => $comparisonPeriod->previousDateFrom->toDateString(),
                    'date_to' => $comparisonPeriod->previousDateTo->toDateString(),
                ],
                ...$analysis['comparison'],
            ],
            'monthly_history' => $this->monthlyHistory($owner, $currency, $dateFrom, $dateTo),
            'category_groups' => $this->categoryGroups($categories, $categoryAmounts),
        ];
    }

    /**
     * @param  array<int|string, ExactInteger>  $categoryAmounts
     * @param  Collection<int, Category>  $categoriesById
     */
    private function addCategoryAmount(
        array &$categoryAmounts,
        ?int $categoryId,
        ExactInteger $amount,
        Collection $categoriesById,
    ): void {
        $category = $categoryId === null ? null : $categoriesById->get($categoryId);

        if ($category === null) {
            $categoryAmounts['uncategorized'] = ($categoryAmounts['uncategorized'] ?? ExactInteger::from(0))
                ->add($amount);

            return;
        }

        $categoryAmounts[$category->id] = ($categoryAmounts[$category->id] ?? ExactInteger::from(0))
            ->add($amount);

        if ($category->parent_id !== null) {
            $categoryAmounts[$category->parent_id] = ($categoryAmounts[$category->parent_id] ?? ExactInteger::from(0))
                ->add($amount);
        }
    }

    /** @return list<ReportMonthData> */
    private function monthlyHistory(
        User $owner,
        Currency $currency,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): array {
        $earliestOccurredOn = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->where('occurred_on', '<=', $dateTo->toDateString())
            ->min('occurred_on');
        $selectedPeriodStart = $dateFrom->startOfMonth();
        $historyStart = is_string($earliestOccurredOn)
            ? CarbonImmutable::parse($earliestOccurredOn, config('app.timezone'))
                ->startOfMonth()
                ->min($selectedPeriodStart)
            : $selectedPeriodStart;

        /** @var array<string, ExactInteger> $monthlyAmounts */
        $monthlyAmounts = [];
        /** @var array<string, int> $monthlyTransactionCounts */
        $monthlyTransactionCounts = [];
        $month = $historyStart;

        while ($month->lessThanOrEqualTo($dateTo)) {
            $monthlyAmounts[$month->format('Y-m')] = ExactInteger::from(0);
            $monthlyTransactionCounts[$month->format('Y-m')] = 0;
            $month = $month->addMonth();
        }

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$historyStart->toDateString(), $dateTo->toDateString()])
            ->select(['id', 'occurred_on', 'amount_minor', 'kind'])
            ->cursor();

        foreach ($transactions as $transaction) {
            $monthKey = $transaction->occurred_on->format('Y-m');
            $monthlyAmounts[$monthKey] = $monthlyAmounts[$monthKey]->add(
                $transaction->kind->signedAmount((string) $transaction->amount_minor),
            );
            $monthlyTransactionCounts[$monthKey]++;
        }

        $history = [];

        foreach ($monthlyAmounts as $monthKey => $amount) {
            $month = CarbonImmutable::parse($monthKey.'-01', config('app.timezone'));
            $monthEnd = $month->endOfMonth()->min($dateTo);
            $history[] = [
                'month' => $monthKey,
                'label' => $month->isoFormat('MMMM YYYY'),
                'date_from' => $month->toDateString(),
                'date_to' => $monthEnd->toDateString(),
                'total_minor' => $amount->value(),
                'transaction_count' => $monthlyTransactionCounts[$monthKey],
            ];
        }

        return $history;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  array<int|string, ExactInteger>  $categoryAmounts
     * @return list<ReportCategoryGroupData>
     */
    private function categoryGroups(Collection $categories, array $categoryAmounts): array
    {
        $childrenByParentId = $categories
            ->whereNotNull('parent_id')
            ->groupBy('parent_id');
        $groups = [];

        foreach ($categories->whereNull('parent_id') as $category) {
            if (! isset($categoryAmounts[$category->id])) {
                continue;
            }

            $children = [];

            foreach ($childrenByParentId->get($category->id, new Collection) as $child) {
                if (! isset($categoryAmounts[$child->id])) {
                    continue;
                }

                $children[] = [
                    'category' => $this->categoryData($child),
                    'amount_minor' => $categoryAmounts[$child->id]->value(),
                ];
            }

            $groups[] = [
                'category' => $this->categoryData($category),
                'amount_minor' => $categoryAmounts[$category->id]->value(),
                'children' => $children,
            ];
        }

        if (isset($categoryAmounts['uncategorized'])) {
            $groups[] = [
                'category' => [
                    'id' => null,
                    'name' => 'Uncategorized',
                    'archived' => false,
                ],
                'amount_minor' => $categoryAmounts['uncategorized']->value(),
                'children' => [],
            ];
        }

        return $groups;
    }

    /** @return ReportCategoryData */
    private function categoryData(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'archived' => $category->archived_at !== null,
        ];
    }

    private function periodLabel(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): string
    {
        if ($dateFrom->isSameDay($dateTo)) {
            return $dateFrom->isoFormat('LL');
        }

        return $dateFrom->isoFormat('ll').' – '.$dateTo->isoFormat('ll');
    }
}
