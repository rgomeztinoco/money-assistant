<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;

final class ReadPeriodSummary
{
    /** @return array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string} */
    public function handle(
        User $owner,
        Currency $currency,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): array {
        $netSpending = ExactInteger::from(0);
        $income = ExactInteger::from(0);
        $movedToSavings = ExactInteger::from(0);

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->select(['id', 'amount_minor', 'kind', 'direction', 'transfer_purpose'])
            ->cursor();

        foreach ($transactions as $transaction) {
            $amount = ExactInteger::from($transaction->amount_minor);
            $netSpending = $netSpending->add(
                $transaction->kind->netSpendingAmount((string) $transaction->amount_minor),
            );

            if ($transaction->kind === TransactionKind::Income) {
                $income = $income->add($amount);
            }

            if ($transaction->kind === TransactionKind::Transfer
                && $transaction->transfer_purpose === TransferPurpose::Savings) {
                $movedToSavings = $transaction->direction === TransactionDirection::Credit
                    ? $movedToSavings->subtract($amount)
                    : $movedToSavings->add($amount);
            }
        }

        return [
            'net_spending_minor' => $netSpending->value(),
            'income_minor' => $income->value(),
            'moved_to_savings_minor' => $movedToSavings->value(),
        ];
    }
}
