<?php

namespace App\Actions\YearlyPayments;

use App\Currency;
use App\ExactInteger;
use App\Models\User;
use App\Models\YearlyPayment;
use App\Models\YearlyPaymentSetting;
use Carbon\CarbonImmutable;

/**
 * @phpstan-type Commitment array{id: int, name: string, amount_minor: string, currency: string, cushion_minor: string, target_minor: string, due_month_day: string|null, next_due_on: string|null, is_active: bool}
 * @phpstan-type Target array{currency: string, annual_target_minor: string, monthly_recommendation_minor: string}
 * @phpstan-type CombinedEstimate array{status: string, currency: string, annual_target_minor: string|null, monthly_recommendation_minor: string|null, unavailable_reason: string|null}
 * @phpstan-type Plan array{commitments: list<Commitment>, native_targets: list<Target>, combined_estimate: CombinedEstimate, planning_rate: array{pen_per_usd: string|null, direction: string, source: string}, upcoming_commitments: list<Commitment>, calculation_date: string, timezone: string, assumptions: array{target_scope: string, monthly_rounding: string, conversion_rounding: string, due_dates: string, planning_only: string}}
 */
final class ReadYearlyPaymentPlan
{
    /** @return Plan */
    public function handle(User $owner): array
    {
        $timezone = config('app.reporting_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $payments = YearlyPayment::query()->whereBelongsTo($owner, 'owner')->orderBy('id')->get();
        $commitments = $payments->map(fn (YearlyPayment $payment): array => [
            'id' => $payment->id,
            'name' => $payment->name,
            'amount_minor' => (string) $payment->amount_minor,
            'currency' => $payment->currency->value,
            'cushion_minor' => (string) $payment->cushion_minor,
            'target_minor' => ExactInteger::from($payment->amount_minor)->add(ExactInteger::from($payment->cushion_minor))->value(),
            'due_month_day' => $payment->due_month_day,
            'next_due_on' => $this->nextDueOn($payment->due_month_day, $today),
            'is_active' => $payment->is_active,
        ])->values()->all();
        $totals = ['PEN' => ExactInteger::from(0), 'USD' => ExactInteger::from(0)];

        foreach ($commitments as $commitment) {
            if ($commitment['is_active']) {
                $totals[$commitment['currency']] = $totals[$commitment['currency']]->add(ExactInteger::from($commitment['target_minor']));
            }
        }

        $rate = YearlyPaymentSetting::query()->whereBelongsTo($owner, 'owner')->first()?->pen_per_usd;
        $combined = [
            'status' => 'unavailable',
            'currency' => Currency::Pen->value,
            'annual_target_minor' => null,
            'monthly_recommendation_minor' => null,
            'unavailable_reason' => 'Enter a planning exchange rate in PEN per USD to include USD commitments.',
        ];

        if ($rate !== null || $totals['USD']->compare(ExactInteger::from(0)) === 0) {
            $scale = $rate !== null && str_contains($rate, '.') ? strlen(explode('.', $rate)[1]) : 0;
            $converted = bcmul($totals['USD']->value(), $rate ?? '0', $scale);
            $annual = bcceil(bcadd($totals['PEN']->value(), $converted, $scale));
            $combined = [...$this->target($annual, Currency::Pen), 'status' => 'available', 'unavailable_reason' => null];
        }

        $upcoming = array_values(array_filter($commitments, fn (array $payment): bool => $payment['is_active']
            && $payment['next_due_on'] !== null
            && $payment['next_due_on'] >= $today->toDateString()
            && $payment['next_due_on'] <= $today->addDays(30)->toDateString()));
        usort($upcoming, fn (array $left, array $right): int => [$left['next_due_on'], $left['id']] <=> [$right['next_due_on'], $right['id']]);

        return [
            'commitments' => array_values($commitments),
            'native_targets' => array_map(fn (Currency $currency): array => $this->target($totals[$currency->value]->value(), $currency), [Currency::Pen, Currency::Usd]),
            'combined_estimate' => $combined,
            'planning_rate' => ['pen_per_usd' => $rate, 'direction' => 'PEN per USD', 'source' => 'manual'],
            'upcoming_commitments' => $upcoming,
            'calculation_date' => $today->toDateString(),
            'timezone' => $timezone,
            'assumptions' => [
                'target_scope' => 'One full annual cycle of all active commitments, including cushions, in each currency.',
                'monthly_rounding' => 'Divide the aggregate annual target by 12 and round upward to the next minor unit.',
                'conversion_rounding' => 'Convert the aggregate USD annual target at the manual PEN per USD rate, add PEN, and round upward once to PEN minor units. Derive the monthly recommendation from that rounded annual estimate.',
                'due_dates' => 'Month/day repeats every year and selects upcoming active payments through 30 days ahead. February 29 falls on February 28 in non-leap years. Dates do not affect targets.',
                'planning_only' => 'Planning targets do not represent money saved, bills paid, or money safe to spend today.',
            ],
        ];
    }

    private function nextDueOn(?string $monthDay, CarbonImmutable $today): ?string
    {
        if ($monthDay === null) {
            return null;
        }

        [$month, $day] = array_map('intval', explode('-', $monthDay));
        $due = $today->setDate($today->year, $month, 1);
        $due = $due->day(min($day, $due->daysInMonth));

        if ($due->lessThan($today)) {
            $due = $today->setDate($today->year + 1, $month, 1);
            $due = $due->day(min($day, $due->daysInMonth));
        }

        return $due->toDateString();
    }

    /** @return Target */
    private function target(string $annualMinor, Currency $currency): array
    {
        $monthly = bcdiv($annualMinor, '12', 0);

        if (bcmod($annualMinor, '12', 0) !== '0') {
            $monthly = bcadd($monthly, '1', 0);
        }

        return ['currency' => $currency->value, 'annual_target_minor' => $annualMinor, 'monthly_recommendation_minor' => $monthly];
    }
}
