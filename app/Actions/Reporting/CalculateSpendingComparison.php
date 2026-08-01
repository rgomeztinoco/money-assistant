<?php

namespace App\Actions\Reporting;

use App\ExactInteger;

/**
 * @phpstan-import-type CategoryTotalData from ReadSpendingSummary
 * @phpstan-import-type CombinedTotalData from ReadSpendingSummary
 *
 * @phpstan-type SpendingSummaryData array{totals: array{USD: string, PEN: string}, combined_total: CombinedTotalData, category_totals: list<CategoryTotalData>}
 * @phpstan-type ComparisonMetricData array{current_amount_minor: string, baseline_average_minor: string, difference_amount_minor: string, difference_percentage_basis_points: string|null}
 * @phpstan-type CombinedComparisonData array{currency: string|null, current_amount_minor: string|null, baseline_average_minor: string|null, difference_amount_minor: string|null, difference_percentage_basis_points: string|null, unavailable_reason: 'reporting_currency_not_selected'|'missing_exchange_rates'|null, missing_rate_dates: list<string>}
 * @phpstan-type CategoryComparisonData array{category: array{id: int|null, name: string}, totals: array{USD: ComparisonMetricData, PEN: ComparisonMetricData}, combined_total: CombinedComparisonData}
 * @phpstan-type SpendingComparisonData array{baseline_months: list<string>, totals: array{USD: ComparisonMetricData, PEN: ComparisonMetricData}, combined_total: CombinedComparisonData, category_totals: list<CategoryComparisonData>}
 */
final class CalculateSpendingComparison
{
    /**
     * @param  list<SpendingSummaryData>  $summaries
     * @return SpendingSummaryData
     */
    public function average(array $summaries): array
    {
        $categories = $this->categoryIndex($summaries);
        $categoryAverages = [];

        foreach ($categories as $categoryKey => $category) {
            $categorySummaries = [];

            foreach ($summaries as $summary) {
                $categorySummaries[] = $this->categoryTotalFor($summary, $categoryKey);
            }

            $categoryAverages[] = [
                'category' => $category,
                'totals' => [
                    'USD' => $this->roundedAverage(array_map(
                        fn (array $summary): string => $summary['totals']['USD'],
                        $categorySummaries,
                    )),
                    'PEN' => $this->roundedAverage(array_map(
                        fn (array $summary): string => $summary['totals']['PEN'],
                        $categorySummaries,
                    )),
                ],
                'combined_total' => $this->averageCombinedTotal($categorySummaries),
            ];
        }

        return [
            'totals' => [
                'USD' => $this->roundedAverage(array_map(
                    fn (array $summary): string => $summary['totals']['USD'],
                    $summaries,
                )),
                'PEN' => $this->roundedAverage(array_map(
                    fn (array $summary): string => $summary['totals']['PEN'],
                    $summaries,
                )),
            ],
            'combined_total' => $this->averageCombinedTotal($summaries),
            'category_totals' => $categoryAverages,
        ];
    }

    /**
     * @param  SpendingSummaryData  $periodSpending
     * @param  SpendingSummaryData  $baselineAverage
     * @param  list<string>  $baselineMonthKeys
     * @return SpendingComparisonData
     */
    public function compare(
        array $periodSpending,
        array $baselineAverage,
        array $baselineMonthKeys,
    ): array {
        $categories = $this->categoryIndex([$baselineAverage, $periodSpending]);
        $categoryComparisons = [];

        foreach ($categories as $categoryKey => $category) {
            $current = $this->categoryTotalFor($periodSpending, $categoryKey);
            $baseline = $this->categoryTotalFor($baselineAverage, $categoryKey);
            $categoryComparisons[] = [
                'category' => $category,
                'totals' => [
                    'USD' => $this->comparisonMetric(
                        $current['totals']['USD'],
                        $baseline['totals']['USD'],
                    ),
                    'PEN' => $this->comparisonMetric(
                        $current['totals']['PEN'],
                        $baseline['totals']['PEN'],
                    ),
                ],
                'combined_total' => $this->comparisonCombinedTotal(
                    $current['combined_total'],
                    $baseline['combined_total'],
                ),
            ];
        }

        return [
            'baseline_months' => $baselineMonthKeys,
            'totals' => [
                'USD' => $this->comparisonMetric(
                    $periodSpending['totals']['USD'],
                    $baselineAverage['totals']['USD'],
                ),
                'PEN' => $this->comparisonMetric(
                    $periodSpending['totals']['PEN'],
                    $baselineAverage['totals']['PEN'],
                ),
            ],
            'combined_total' => $this->comparisonCombinedTotal(
                $periodSpending['combined_total'],
                $baselineAverage['combined_total'],
            ),
            'category_totals' => $categoryComparisons,
        ];
    }

    /**
     * @param  list<SpendingSummaryData>  $summaries
     * @return array<int|string, array{id: int|null, name: string}>
     */
    private function categoryIndex(array $summaries): array
    {
        $categories = [];

        foreach ($summaries as $summary) {
            foreach ($summary['category_totals'] as $categoryTotal) {
                $categoryKey = $categoryTotal['category']['id'] ?? 'uncategorized';
                $categories[$categoryKey] = $categoryTotal['category'];
            }
        }

        return $categories;
    }

    /**
     * @param  SpendingSummaryData  $summary
     * @return CategoryTotalData
     */
    private function categoryTotalFor(array $summary, int|string $categoryKey): array
    {
        foreach ($summary['category_totals'] as $categoryTotal) {
            if (($categoryTotal['category']['id'] ?? 'uncategorized') === $categoryKey) {
                return $categoryTotal;
            }
        }

        return [
            'category' => [
                'id' => is_int($categoryKey) ? $categoryKey : null,
                'name' => is_int($categoryKey) ? '' : 'Uncategorized',
            ],
            'totals' => ['USD' => '0', 'PEN' => '0'],
            'combined_total' => $this->zeroCombinedTotal($summary),
        ];
    }

    /**
     * @param  SpendingSummaryData  $summary
     * @return CombinedTotalData
     */
    private function zeroCombinedTotal(array $summary): array
    {
        return [
            'currency' => $summary['combined_total']['currency'],
            'amount_minor' => '0',
            'unavailable_reason' => null,
            'missing_rate_dates' => [],
        ];
    }

    /**
     * @return ComparisonMetricData
     */
    private function comparisonMetric(int|string $currentAmount, int|string $baselineAmount): array
    {
        $current = ExactInteger::from($currentAmount);
        $baseline = ExactInteger::from($baselineAmount);
        $difference = $current->subtract($baseline);

        return [
            'current_amount_minor' => $current->value(),
            'baseline_average_minor' => $baseline->value(),
            'difference_amount_minor' => $difference->value(),
            'difference_percentage_basis_points' => $baseline->compare(ExactInteger::from(0)) === 0
                ? null
                : $this->roundedDivide(
                    $difference->multiply(ExactInteger::from(10_000)),
                    $baseline,
                )->value(),
        ];
    }

    /**
     * @param  CombinedTotalData  $current
     * @param  CombinedTotalData  $baseline
     * @return CombinedComparisonData
     */
    private function comparisonCombinedTotal(array $current, array $baseline): array
    {
        $missingRateDates = array_values(array_unique([
            ...$current['missing_rate_dates'],
            ...$baseline['missing_rate_dates'],
        ]));
        sort($missingRateDates);
        $currency = $current['currency'] ?? $baseline['currency'];

        if ($currency === null) {
            return [
                'currency' => null,
                'current_amount_minor' => null,
                'baseline_average_minor' => null,
                'difference_amount_minor' => null,
                'difference_percentage_basis_points' => null,
                'unavailable_reason' => 'reporting_currency_not_selected',
                'missing_rate_dates' => [],
            ];
        }

        if ($current['amount_minor'] === null || $baseline['amount_minor'] === null) {
            return [
                'currency' => $currency,
                'current_amount_minor' => $current['amount_minor'],
                'baseline_average_minor' => $baseline['amount_minor'],
                'difference_amount_minor' => null,
                'difference_percentage_basis_points' => null,
                'unavailable_reason' => 'missing_exchange_rates',
                'missing_rate_dates' => $missingRateDates,
            ];
        }

        return [
            'currency' => $currency,
            ...$this->comparisonMetric($current['amount_minor'], $baseline['amount_minor']),
            'unavailable_reason' => null,
            'missing_rate_dates' => [],
        ];
    }

    /**
     * @param  list<array{combined_total: CombinedTotalData}>  $summaries
     * @return CombinedTotalData
     */
    private function averageCombinedTotal(array $summaries): array
    {
        $combinedTotals = array_column($summaries, 'combined_total');
        $currency = $combinedTotals[0]['currency'];
        $missingRateDateSet = [];

        foreach ($combinedTotals as $combinedTotal) {
            foreach ($combinedTotal['missing_rate_dates'] as $missingRateDate) {
                $missingRateDateSet[$missingRateDate] = true;
            }
        }

        $missingRateDates = array_keys($missingRateDateSet);
        sort($missingRateDates);

        if ($currency === null) {
            return [
                'currency' => null,
                'amount_minor' => null,
                'unavailable_reason' => 'reporting_currency_not_selected',
                'missing_rate_dates' => [],
            ];
        }

        if ($missingRateDates !== []) {
            return [
                'currency' => $currency,
                'amount_minor' => null,
                'unavailable_reason' => 'missing_exchange_rates',
                'missing_rate_dates' => $missingRateDates,
            ];
        }

        return [
            'currency' => $currency,
            'amount_minor' => $this->roundedAverage(array_column($combinedTotals, 'amount_minor')),
            'unavailable_reason' => null,
            'missing_rate_dates' => [],
        ];
    }

    /** @param list<int|string> $amounts */
    private function roundedAverage(array $amounts): string
    {
        $total = ExactInteger::from(0);

        foreach ($amounts as $amount) {
            $total = $total->add(ExactInteger::from($amount));
        }

        return $this->roundedDivide($total, ExactInteger::from(count($amounts)))->value();
    }

    private function roundedDivide(ExactInteger $dividend, ExactInteger $divisor): ExactInteger
    {
        $quotient = $dividend->divide($divisor);
        $remainder = $dividend->remainder($divisor);
        $absoluteRemainder = $remainder->compare(ExactInteger::from(0)) < 0
            ? ExactInteger::from(0)->subtract($remainder)
            : $remainder;
        $absoluteDivisor = $divisor->compare(ExactInteger::from(0)) < 0
            ? ExactInteger::from(0)->subtract($divisor)
            : $divisor;

        if ($absoluteRemainder->multiply(ExactInteger::from(2))->compare($absoluteDivisor) >= 0) {
            $hasNegativeResult = $dividend->compare(ExactInteger::from(0)) < 0
                xor $divisor->compare(ExactInteger::from(0)) < 0;
            $quotient = $hasNegativeResult
                ? $quotient->subtract(ExactInteger::from(1))
                : $quotient->add(ExactInteger::from(1));
        }

        return $quotient;
    }
}
