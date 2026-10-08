<?php

namespace App\Actions\YearlyPayments;

use App\Currency;
use App\ExactInteger;
use App\Models\User;
use App\Models\YearlyPayment;
use App\Models\YearlyPaymentSetting;
use Carbon\CarbonImmutable;

/**
 * @phpstan-type Commitment array{id: int, name: string, amount_minor: string, currency: string, cushion_minor: string, target_minor: string, expected_due_on: string|null, is_active: bool}
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
            'expected_due_on' => $payment->expected_due_on?->toDateString(),
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
            && $payment['expected_due_on'] !== null
            && $payment['expected_due_on'] >= $today->toDateString()
            && $payment['expected_due_on'] <= $today->addDays(30)->toDateString()));
        usort($upcoming, fn (array $left, array $right): int => [$left['expected_due_on'], $left['id']] <=> [$right['expected_due_on'], $right['id']]);

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
                'due_dates' => 'Dates only select upcoming active payments from today through 30 days ahead, inclusive. Dates do not affect targets or advance automatically.',
                'planning_only' => 'Planning targets do not represent money saved, bills paid, or money safe to spend today.',
            ],
        ];
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
