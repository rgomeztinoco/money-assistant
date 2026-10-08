<?php

namespace App\Actions\Debts;

use App\DebtEntryKind;
use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Validation\ValidationException;

class SyncDebtAllocation
{
    public function handle(User $owner, Transaction $transaction, ?int $debtId, ?DebtEntryKind $kind, bool $unlink): void
    {
        $entry = $transaction->debtEntry()->lockForUpdate()->first();
        if ($unlink) {
            if ($transaction->kind === TransactionKind::Debt) {
                throw ValidationException::withMessages(['kind' => 'Choose the replacement Kind when unlinking a debt.']);
            }
            $entry?->delete();

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
        if ($debt === null || $kind === null || $kind === DebtEntryKind::Adjustment) {
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
        $entry ??= new DebtEntry(['transaction_id' => $transaction->id]);
        $entry->fill(['debt_id' => $debt->id, 'kind' => $kind, 'amount_minor' => $transaction->amount_minor, 'occurred_on' => $transaction->occurred_on])->save();
    }
}
