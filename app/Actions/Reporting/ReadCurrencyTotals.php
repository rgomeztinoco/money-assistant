<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use App\Models\Transaction;
use App\TransactionKind;
use Carbon\CarbonImmutable;

final class ReadCurrencyTotals
{
    /** @return array{USD: string, PEN: string} */
    public function handle(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        $totals = [
            Currency::Usd->value => ExactInteger::from(0),
            Currency::Pen->value => ExactInteger::from(0),
        ];
        $transactions = Transaction::query()
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->select(['id', 'amount_minor', 'currency', 'kind'])
            ->cursor();

        foreach ($transactions as $transaction) {
            $amount = ExactInteger::from((string) $transaction->amount_minor);
            $signedAmount = $transaction->kind === TransactionKind::Refund
                ? ExactInteger::from(0)->subtract($amount)
                : $amount;
            $currency = $transaction->currency->value;
            $totals[$currency] = $totals[$currency]->add($signedAmount);
        }

        return [
            Currency::Usd->value => $totals[Currency::Usd->value]->value(),
            Currency::Pen->value => $totals[Currency::Pen->value]->value(),
        ];
    }
}
