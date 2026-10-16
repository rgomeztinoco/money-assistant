<?php

namespace App\Actions\Debts;

use App\Actions\Ledger\UpdateTransaction;
use App\DebtEntryKind;
use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateDebtEntry
{
    public function __construct(private UpdateTransaction $updateTransaction) {}

    public function handle(User $owner, Debt $debt, DebtEntry $entry, CarbonImmutable $date, int $amount, ?string $description, ?string $reason, ?int $principal = null, ?int $interest = null, ?int $categoryId = null): void
    {
        DB::transaction(function () use ($owner, $debt, $entry, $date, $amount, $description, $reason, $principal, $interest, $categoryId): void {
            $debt = Debt::query()->whereBelongsTo($owner, 'owner')->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $entry = $debt->entries()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($date->lt($debt->opened_on)) {
                throw ValidationException::withMessages(['occurred_on' => 'Choose a date on or after the opening date.']);
            }
            if ($entry->kind === DebtEntryKind::InterestCharge) {
                throw ValidationException::withMessages(['kind' => 'Correct interest charges with a dated balance adjustment.']);
            }
            if ($entry->kind === DebtEntryKind::Adjustment) {
                $entry->fill(['amount_minor' => $amount, 'occurred_on' => $date, 'reason' => Str::squish($reason ?? '')]);
                $balanceChanged = $entry->isDirty('amount_minor');
                $entry->save();
                if ($balanceChanged) {
                    $debt->ensureNonNegativeBalance('amount_minor');
                }

                return;
            }

            $transaction = $entry->transaction()->whereBelongsTo($owner, 'owner')->lockForUpdate()->firstOrFail();
            $this->updateTransaction->handle(
                owner: $owner, transaction: $transaction, occurredOn: $date, amountMinor: $amount,
                currency: $transaction->currency, kind: TransactionKind::Debt, direction: $transaction->direction,
                description: $description ?? $transaction->description, incomeSource: null, transferPurpose: null,
                instrumentLabel: $transaction->instrument_label, instrumentLastFour: $transaction->instrument_last_four,
                categoryId: $categoryId, originalSpendingId: null, removeReceiptBreakdown: false,
                debtId: $debt->id, debtEntryKind: $entry->kind, debtPrincipalMinor: $principal, debtInterestMinor: $interest,
            );
        }, 3);
    }
}
