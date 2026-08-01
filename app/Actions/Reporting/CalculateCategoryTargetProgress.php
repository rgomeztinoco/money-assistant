<?php

namespace App\Actions\Reporting;

use App\ExactInteger;

/**
 * @phpstan-type CategoryTargetProgressData array{
 *     spent_minor: string|null,
 *     remaining_minor: string|null,
 *     percentage_basis_points: string|null,
 *     state: 'remaining'|'met'|'exceeded'|'unavailable',
 *     period_status: 'completed'|'to_date',
 *     unavailable_reason: 'missing_exchange_rates'|null,
 *     missing_rate_dates: list<string>
 * }
 */
final class CalculateCategoryTargetProgress
{
    /**
     * @param  'missing_exchange_rates'|null  $unavailableReason
     * @param  list<string>  $missingRateDates
     * @return CategoryTargetProgressData
     */
    public function handle(
        string $targetAmountMinor,
        ?string $spentMinor,
        bool $isComplete,
        ?string $unavailableReason = null,
        array $missingRateDates = [],
    ): array {
        if ($spentMinor === null) {
            return [
                'spent_minor' => null,
                'remaining_minor' => null,
                'percentage_basis_points' => null,
                'state' => 'unavailable',
                'period_status' => $isComplete ? 'completed' : 'to_date',
                'unavailable_reason' => $unavailableReason,
                'missing_rate_dates' => $missingRateDates,
            ];
        }

        $target = ExactInteger::from($targetAmountMinor);
        $spent = ExactInteger::from($spentMinor);
        $remaining = $target->subtract($spent);

        return [
            'spent_minor' => $spent->value(),
            'remaining_minor' => $remaining->value(),
            'percentage_basis_points' => $target->compare(ExactInteger::from(0)) === 0
                ? null
                : $this->roundedDivide(
                    $spent->multiply(ExactInteger::from(10_000)),
                    $target,
                )->value(),
            'state' => match ($remaining->compare(ExactInteger::from(0))) {
                1 => 'remaining',
                0 => 'met',
                default => 'exceeded',
            },
            'period_status' => $isComplete ? 'completed' : 'to_date',
            'unavailable_reason' => null,
            'missing_rate_dates' => [],
        ];
    }

    private function roundedDivide(ExactInteger $dividend, ExactInteger $divisor): ExactInteger
    {
        $quotient = $dividend->divide($divisor);
        $remainder = $dividend->remainder($divisor);
        $absoluteRemainder = $remainder->compare(ExactInteger::from(0)) < 0
            ? ExactInteger::from(0)->subtract($remainder)
            : $remainder;

        if ($absoluteRemainder->multiply(ExactInteger::from(2))->compare($divisor) >= 0) {
            $quotient = $dividend->compare(ExactInteger::from(0)) < 0
                ? $quotient->subtract(ExactInteger::from(1))
                : $quotient->add(ExactInteger::from(1));
        }

        return $quotient;
    }
}
