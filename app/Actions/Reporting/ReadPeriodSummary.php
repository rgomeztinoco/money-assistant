<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\DebtEntryKind;
use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;

final class ReadPeriodSummary
{
    /** @return array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string, debt_payments_made_minor: string, debt_payments_received_minor: string} */
    public function handle(
        User $owner,
        Currency $currency,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): array {
        $netSpending = ExactInteger::from(0);
        $income = ExactInteger::from(0);
        $movedToSavings = ExactInteger::from(0);
        $paymentsMade = ExactInteger::from(0);
        $paymentsReceived = ExactInteger::from(0);

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->with('debtEntry')
            ->select(['id', 'amount_minor', 'kind', 'direction', 'transfer_purpose'])
            ->lazy(500);

        foreach ($transactions as $transaction) {
            $amount = ExactInteger::from($transaction->amount_minor);
            $netSpending = $netSpending->add(
                $transaction->netSpendingAmount(),
            );

            if ($transaction->kind === TransactionKind::Debt && $transaction->debtEntry?->kind === DebtEntryKind::Repayment) {
                if ($transaction->direction === MovementDirection::Debit) {
                    $paymentsMade = $paymentsMade->add($amount);
                } else {
                    $paymentsReceived = $paymentsReceived->add($amount);
                }
            }

            $income = $income->add($transaction->incomeAmount());

            if ($transaction->kind === TransactionKind::Transfer
                && $transaction->transfer_purpose === TransferPurpose::Savings) {
                $movedToSavings = $transaction->direction === MovementDirection::Credit
                    ? $movedToSavings->subtract($amount)
                    : $movedToSavings->add($amount);
            }
        }

        return [
            'net_spending_minor' => $netSpending->value(),
            'income_minor' => $income->value(),
            'moved_to_savings_minor' => $movedToSavings->value(),
            'debt_payments_made_minor' => $paymentsMade->value(),
            'debt_payments_received_minor' => $paymentsReceived->value(),
        ];
    }
}
