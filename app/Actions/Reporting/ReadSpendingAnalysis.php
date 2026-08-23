<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @phpstan-type SpendingComparisonData array{
 *     current_total_minor: string,
 *     previous_total_minor: string,
 *     change_minor: string,
 *     percentage_change: string|null,
 *     direction: 'increased'|'decreased'|'unchanged'|'no_baseline'|'no_activity'
 * }
 * @phpstan-type SpendingCategoryInsightData array{
 *     category: array{id: int|null, name: string},
 *     current_total_minor: string,
 *     previous_total_minor: string,
 *     change_minor: string
 * }
 */
final class ReadSpendingAnalysis
{
    public function __construct(private NetSpendingAllocation $netSpendingAllocation) {}

    /**
     * @return array{
     *     comparison: SpendingComparisonData,
     *     category_insights: list<SpendingCategoryInsightData>
     * }
     */
    public function handle(
        User $owner,
        Currency $currency,
        SpendingComparisonPeriod $period,
    ): array {
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->get(['id', 'parent_id', 'name']);
        $categoriesById = $categories->keyBy('id');
        $totals = [
            'current' => ExactInteger::from(0),
            'previous' => ExactInteger::from(0),
        ];
        $activityCount = 0;

        /** @var array<int|string, array{current: ExactInteger, previous: ExactInteger}> $categoryAmounts */
        $categoryAmounts = [];

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->where(function (Builder $query) use ($period): void {
                $query
                    ->whereBetween('occurred_on', [$period->currentDateFrom->toDateString(), $period->currentDateTo->toDateString()])
                    ->orWhereBetween('occurred_on', [$period->previousDateFrom->toDateString(), $period->previousDateTo->toDateString()]);
            })
            ->select(['id', 'occurred_on', 'amount_minor', 'kind', 'category_id'])
            ->with([
                'receiptBreakdown:id,transaction_id',
                'receiptBreakdown.lineItems:id,receipt_breakdown_id,category_id,line_total_minor',
            ])
            ->lazy();

        foreach ($transactions as $transaction) {
            $activityCount++;
            $periodName = $transaction->occurred_on->betweenIncluded($period->currentDateFrom, $period->currentDateTo)
                ? 'current'
                : 'previous';
            $transactionAmount = $transaction->kind->netSpendingAmount((string) $transaction->amount_minor);
            $totals[$periodName] = $totals[$periodName]->add($transactionAmount);

            foreach ($this->netSpendingAllocation->byTopLevelCategory($transaction, $categoriesById) as $categoryKey => $amount) {
                $categoryAmounts[$categoryKey] ??= [
                    'current' => ExactInteger::from(0),
                    'previous' => ExactInteger::from(0),
                ];
                $categoryAmounts[$categoryKey][$periodName] = $categoryAmounts[$categoryKey][$periodName]->add($amount);
            }
        }

        return [
            'comparison' => $this->comparison(
                current: $totals['current'],
                previous: $totals['previous'],
                hasActivity: $activityCount > 0,
            ),
            'category_insights' => $this->categoryInsights($categoryAmounts, $categoriesById),
        ];
    }

    /** @return SpendingComparisonData */
    private function comparison(
        ExactInteger $current,
        ExactInteger $previous,
        bool $hasActivity,
    ): array {
        $zero = ExactInteger::from(0);
        $change = $current->subtract($previous);
        $previousComparison = $previous->compare($zero);
        $currentComparison = $current->compare($zero);
        $changeComparison = $change->compare($zero);

        if ($previousComparison === 0 && $currentComparison === 0 && ! $hasActivity) {
            $direction = 'no_activity';
        } elseif ($changeComparison === 0) {
            $direction = 'unchanged';
        } elseif ($previousComparison === 0) {
            $direction = 'no_baseline';
        } elseif ($changeComparison === 1) {
            $direction = 'increased';
        } else {
            $direction = 'decreased';
        }

        $percentageChange = $previousComparison === 0
            ? null
            : $this->percentageChange($change, $previous);

        return [
            'current_total_minor' => $current->value(),
            'previous_total_minor' => $previous->value(),
            'change_minor' => $change->value(),
            'percentage_change' => $percentageChange,
            'direction' => $direction,
        ];
    }

    /**
     * @param  array<int|string, array{current: ExactInteger, previous: ExactInteger}>  $categoryAmounts
     * @param  Collection<int, Category>  $categoriesById
     * @return list<SpendingCategoryInsightData>
     */
    private function categoryInsights(array $categoryAmounts, Collection $categoriesById): array
    {
        $insights = [];

        foreach ($categoryAmounts as $categoryId => $amounts) {
            $change = $amounts['current']->subtract($amounts['previous']);
            if ($categoryId === 'uncategorized') {
                $category = ['id' => null, 'name' => 'Uncategorized'];
            } else {
                $categoryModel = $categoriesById->get($categoryId);
                $category = ['id' => $categoryModel->id, 'name' => $categoryModel->name];
            }
            $insights[] = [
                'category' => $category,
                'current_total_minor' => $amounts['current']->value(),
                'previous_total_minor' => $amounts['previous']->value(),
                'change_minor' => $change->value(),
            ];
        }

        usort($insights, function (array $left, array $right): int {
            $changeComparison = $this->absolute(ExactInteger::from($right['change_minor']))
                ->compare($this->absolute(ExactInteger::from($left['change_minor'])));

            return $changeComparison !== 0
                ? $changeComparison
                : $this->absolute(ExactInteger::from($right['current_total_minor']))
                    ->compare($this->absolute(ExactInteger::from($left['current_total_minor'])));
        });

        return array_values(Arr::take($insights, 3));
    }

    private function absolute(ExactInteger $amount): ExactInteger
    {
        return $amount->compare(ExactInteger::from(0)) === -1
            ? ExactInteger::from(0)->subtract($amount)
            : $amount;
    }

    private function percentageChange(ExactInteger $change, ExactInteger $previous): string
    {
        $numerator = $change->multiply(ExactInteger::from(100))->value();
        $denominator = $this->absolute($previous)->value();
        $percentageChange = bcdiv($numerator, $denominator, 2);

        if (bccomp($percentageChange, '0', 2) === 0 && $change->compare(ExactInteger::from(0)) !== 0) {
            $percentageChange = bcdiv(
                $numerator,
                $denominator,
                max(2, Str::length($denominator)),
            );
        }

        return Str::of($percentageChange)
            ->rtrim('0')
            ->rtrim('.')
            ->toString();
    }
}
