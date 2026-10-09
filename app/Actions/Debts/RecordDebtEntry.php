<?php

namespace App\Actions\Debts;

use App\Actions\Ledger\RecordManualTransaction;
use App\Actions\Ledger\UpdateTransaction;
use App\DebtEntryKind;
use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordDebtEntry
{
    public function __construct(private RecordManualTransaction $recordManualTransaction, private UpdateTransaction $updateTransaction) {}

    public function handle(User $owner, Debt $debt, DebtEntryKind $kind, ?CarbonImmutable $date, ?int $amount, ?string $description, ?string $reason, ?int $transactionId, ?int $principal = null, ?int $interest = null, bool $interestIsNew = false, ?int $categoryId = null): void
    {
        DB::transaction(function () use ($owner, $debt, $kind, $date, $amount, $description, $reason, $transactionId, $principal, $interest, $interestIsNew, $categoryId): void {
            $debt = Debt::query()->whereBelongsTo($owner, 'owner')->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (in_array($kind, [DebtEntryKind::Adjustment, DebtEntryKind::InterestCharge], true)) {
                if ($date === null || $date->lt($debt->opened_on)) {
                    throw ValidationException::withMessages(['occurred_on' => 'Choose a date on or after the opening date.']);
                }
                DebtEntry::create(['debt_id' => $debt->id, 'kind' => $kind, 'amount_minor' => $amount, 'occurred_on' => $date, 'reason' => Str::squish($reason ?? '')]);

                return;
            }
            $transaction = $transactionId === null
                ? $this->recordManualTransaction->handle(owner: $owner, occurredOn: $date ?? $debt->opened_on, amountMinor: $amount, currency: $debt->currency, kind: TransactionKind::Debt, description: $description ?? $debt->name, direction: $debt->direction->movementDirection($kind))
                : Transaction::query()->whereBelongsTo($owner, 'owner')->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            if ($transaction->debtEntry()->exists() || $transaction->voided_at !== null) {
                throw ValidationException::withMessages(['transaction_id' => 'Choose an active Transaction that has not already been allocated to a debt.']);
            }
            $this->updateTransaction->handle(owner: $owner, transaction: $transaction, occurredOn: $transaction->occurred_on, amountMinor: $transaction->amount_minor,
                currency: $transaction->currency, kind: TransactionKind::Debt, direction: $transaction->direction, description: $transaction->description,
                incomeSource: null, transferPurpose: null, instrumentLabel: $transaction->instrument_label, instrumentLastFour: $transaction->instrument_last_four,
                categoryId: $categoryId, originalSpendingId: null, removeReceiptBreakdown: true, debtId: $debt->id, debtEntryKind: $kind, debtPrincipalMinor: $principal, debtInterestMinor: $interest, debtInterestIsNew: $interestIsNew);
        }, 3);
    }
}
