<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ReadCurrencyTotals
{
    /** @return array{USD: string, PEN: string} */
    public function handle(User $owner, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        $totals = [
            Currency::Usd->value => ExactInteger::from(0),
            Currency::Pen->value => ExactInteger::from(0),
        ];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->select(['id', 'amount_minor', 'currency', 'kind'])
            ->cursor();

        foreach ($transactions as $transaction) {
            $signedAmount = $transaction->kind->netSpendingAmount((string) $transaction->amount_minor);
            $currency = $transaction->currency->value;
            $totals[$currency] = $totals[$currency]->add($signedAmount);
        }

        return [
            Currency::Usd->value => $totals[Currency::Usd->value]->value(),
            Currency::Pen->value => $totals[Currency::Pen->value]->value(),
        ];
    }
}
