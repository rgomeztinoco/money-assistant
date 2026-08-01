<?php

namespace App\Actions\Reporting;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type SpendingComparisonData from CalculateSpendingComparison
 * @phpstan-import-type SpendingSummaryData from CalculateSpendingComparison
 *
 * @phpstan-type BaselineMonthData array{month: string, label: string, date_from: string, date_to: string, spending: SpendingSummaryData}
 */
final class ReadSpendingInsights
{
    public function __construct(
        private ReadSpendingSummary $readSpendingSummary,
        private CalculateSpendingComparison $calculateSpendingComparison,
    ) {}

    /**
     * @return array{
     *     period: array{month: string, label: string, date_from: string, date_to: string, is_complete: bool, spending_label: 'Completed spending'|'Spending to date', spending: SpendingSummaryData},
     *     baseline: array{status: 'unavailable'|'provisional'|'established', complete_month_count: int, months: list<BaselineMonthData>, average: SpendingSummaryData|null},
     *     comparison: SpendingComparisonData|null
     * }
     */
    public function handle(User $owner, CarbonImmutable $selectedMonth): array
    {
        $today = CarbonImmutable::today();
        $selectedMonth = $selectedMonth->startOfMonth();
        $selectedMonthEnd = $selectedMonth->endOfMonth();
        $periodEnd = $selectedMonth->isSameMonth($today)
            ? $today
            : $selectedMonthEnd;
        $incompleteMonthKeys = $this->incompleteMonthKeys($owner, $selectedMonthEnd);

        /** @var list<BaselineMonthData> $completeMonths */
        $completeMonths = [];
        $earliestOccurredOn = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->min('occurred_on');

        if (is_string($earliestOccurredOn)) {
            $candidateMonth = CarbonImmutable::parse($earliestOccurredOn)->startOfMonth();

            while ($candidateMonth->lessThan($selectedMonth) && $candidateMonth->endOfMonth()->lessThan($today)) {
                if (isset($incompleteMonthKeys[$candidateMonth->format('Y-m')])) {
                    $candidateMonth = $candidateMonth->addMonth();

                    continue;
                }

                $completeMonths[] = [
                    'month' => $candidateMonth->format('Y-m'),
                    'label' => $candidateMonth->isoFormat('MMMM YYYY'),
                    'date_from' => $candidateMonth->toDateString(),
                    'date_to' => $candidateMonth->endOfMonth()->toDateString(),
                    'spending' => $this->readSpendingSummary->handle(
                        owner: $owner,
                        dateFrom: $candidateMonth,
                        dateTo: $candidateMonth->endOfMonth(),
                    ),
                ];
                $candidateMonth = $candidateMonth->addMonth();
            }
        }

        $baselineMonths = array_slice($completeMonths, -3);
        $completeMonthCount = count($baselineMonths);
        $baselineAverage = $completeMonthCount === 3
            ? $this->calculateSpendingComparison->average(array_map(
                fn (array $month): array => $month['spending'],
                $baselineMonths,
            ))
            : null;
        $periodSpending = $this->readSpendingSummary->handle(
            owner: $owner,
            dateFrom: $selectedMonth,
            dateTo: $periodEnd,
        );
        $isComplete = $selectedMonthEnd->lessThan($today)
            && ! isset($incompleteMonthKeys[$selectedMonth->format('Y-m')]);

        return [
            'period' => [
                'month' => $selectedMonth->format('Y-m'),
                'label' => $selectedMonth->isoFormat('MMMM YYYY'),
                'date_from' => $selectedMonth->toDateString(),
                'date_to' => $periodEnd->toDateString(),
                'is_complete' => $isComplete,
                'spending_label' => $isComplete
                    ? 'Completed spending'
                    : 'Spending to date',
                'spending' => $periodSpending,
            ],
            'baseline' => [
                'status' => match (true) {
                    $completeMonthCount === 0 => 'unavailable',
                    $completeMonthCount < 3 => 'provisional',
                    default => 'established',
                },
                'complete_month_count' => $completeMonthCount,
                'months' => $baselineMonths,
                'average' => $baselineAverage,
            ],
            'comparison' => $isComplete && $baselineAverage !== null
                ? $this->calculateSpendingComparison->compare(
                    periodSpending: $periodSpending,
                    baselineAverage: $baselineAverage,
                    baselineMonthKeys: array_column($baselineMonths, 'month'),
                )
                : null,
        ];
    }

    /** @return array<string, true> */
    private function incompleteMonthKeys(User $owner, CarbonImmutable $throughMonth): array
    {
        $incompleteMonthKeys = [];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->where('occurred_on', '<=', $throughMonth->endOfMonth()->toDateString())
            ->whereRequiresReview()
            ->select('occurred_on')
            ->cursor();

        foreach ($transactions as $transaction) {
            $incompleteMonthKeys[$transaction->occurred_on->format('Y-m')] = true;
        }

        return $incompleteMonthKeys;
    }
}
