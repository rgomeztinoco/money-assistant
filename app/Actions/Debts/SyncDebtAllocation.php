<?php

namespace App\Actions\Debts;

use App\DebtEntryKind;
use App\ExactInteger;
use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Validation\ValidationException;

class SyncDebtAllocation
{
    public function handle(User $owner, Transaction $transaction, ?int $debtId, ?DebtEntryKind $kind, bool $unlink, ?int $principal = null, ?int $interest = null, bool $interestIsNew = false): void
    {
        $entry = $transaction->debtEntry()->lockForUpdate()->first();
        $previousDebt = $entry === null ? null : Debt::query()
            ->whereBelongsTo($owner, 'owner')->whereKey($entry->debt_id)->lockForUpdate()->firstOrFail();
        if ($unlink) {
            if ($transaction->kind === TransactionKind::Debt) {
                throw ValidationException::withMessages(['kind' => 'Choose the replacement Kind when unlinking a debt.']);
            }
            $entry?->delete();
            if ($entry !== null && $transaction->voided_at === null) {
                $previousDebt->ensureNonNegativeBalance('unlink_debt');
            }

            return;
        }
        if ($transaction->kind !== TransactionKind::Debt) {
            if ($debtId !== null || $kind !== null) {
                throw ValidationException::withMessages(['kind' => 'Debt allocations require the Debt Kind.']);
            }
            if ($entry !== null) {
                throw ValidationException::withMessages(['unlink_debt' => 'Explicitly unlink the debt before changing its Kind.']);
            }

            return;
        }
        $debtId ??= $entry?->debt_id;
        $kind ??= $entry?->kind;
        $debt = Debt::query()->whereBelongsTo($owner, 'owner')->whereKey($debtId)->lockForUpdate()->first();
        if ($debt === null || $kind === null || in_array($kind, [DebtEntryKind::Adjustment, DebtEntryKind::InterestCharge], true)) {
            throw ValidationException::withMessages(['debt_id' => 'Choose a debt and funding or repayment allocation.']);
        }
        if ($transaction->currency !== $debt->currency) {
            throw ValidationException::withMessages(['currency' => 'The Transaction and debt must use the same currency.']);
        }
        if ($transaction->direction !== $debt->direction->movementDirection($kind)) {
            throw ValidationException::withMessages(['direction' => 'This movement direction is incompatible with the debt allocation.']);
        }
        if ($transaction->occurred_on->lt($debt->opened_on)) {
            throw ValidationException::withMessages(['occurred_on' => 'Movements before the opening date are already included in the baseline.']);
        }
        if ($transaction->linkedRefunds()->whereNull('voided_at')->exists()) {
            throw ValidationException::withMessages(['debt_id' => 'Unlink the active Refunds before allocating this Transaction.']);
        }
        if ($interestIsNew && ($entry !== null || $kind !== DebtEntryKind::Repayment || DebtEntry::query()->where('confirmed_with_transaction_id', $transaction->id)->exists())) {
            throw ValidationException::withMessages(['interest_is_new' => 'Record additional charges separately once a payment is allocated.']);
        }
        if ($principal === null && $interest === null) {
            $principal = ($entry->interest_minor ?? 0) === 0 ? $transaction->amount_minor : $entry->principal_minor;
            $interest = $entry->interest_minor ?? 0;
        } elseif ($principal === null || $interest === null) {
            throw ValidationException::withMessages(['principal_minor' => 'Confirm both principal and interest amounts.']);
        }
        if ($principal < 0 || $interest < 0 || ExactInteger::from($principal)->add(ExactInteger::from($interest))->compare(ExactInteger::from($transaction->amount_minor)) !== 0) {
            throw ValidationException::withMessages(['principal_minor' => 'Principal plus interest must equal the full posted amount.']);
        }
        if ($kind === DebtEntryKind::Funding && $interest !== 0) {
            throw ValidationException::withMessages(['interest_minor' => 'Funding must be entirely principal.']);
        }
        if ($interestIsNew && $interest > 0) {
            DebtEntry::create(['debt_id' => $debt->id, 'kind' => DebtEntryKind::InterestCharge, 'amount_minor' => $interest, 'occurred_on' => $transaction->occurred_on, 'confirmed_with_transaction_id' => $transaction->id, 'reason' => 'Interest confirmed with payment']);
        }
        $entry ??= new DebtEntry(['transaction_id' => $transaction->id]);
        $entry->fill(['debt_id' => $debt->id, 'kind' => $kind, 'amount_minor' => $transaction->amount_minor, 'principal_minor' => $principal, 'interest_minor' => $interest, 'occurred_on' => $transaction->occurred_on]);
        $balanceChanged = ! $entry->exists || $entry->isDirty(['debt_id', 'kind', 'amount_minor']);
        $entry->save();
        if ($balanceChanged && $transaction->voided_at === null) {
            $debt->ensureNonNegativeBalance('amount_minor');
            if ($previousDebt !== null && $previousDebt->id !== $debt->id) {
                $previousDebt->ensureNonNegativeBalance('debt_id');
            }
        }
    }
}
