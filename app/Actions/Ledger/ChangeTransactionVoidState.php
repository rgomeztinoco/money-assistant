<?php

namespace App\Actions\Ledger;

use App\Models\Transaction;
use App\Models\User;
use App\TransactionVoidOperation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChangeTransactionVoidState
{
    public function handle(
        User $owner,
        Transaction $transaction,
        TransactionVoidOperation $operation,
    ): Transaction {
        return DB::transaction(function () use ($owner, $transaction, $operation): Transaction {
            $currentTransaction = Transaction::query()
                ->whereKey($transaction->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureOperationChangesState($currentTransaction, $operation);

            $currentTransaction->voided_at = $operation === TransactionVoidOperation::Void
                ? now()->toImmutable()
                : null;
            $currentTransaction->save();

            return $currentTransaction;
        }, 3);
    }

    private function ensureOperationChangesState(
        Transaction $transaction,
        TransactionVoidOperation $operation,
    ): void {
        if (
            $operation === TransactionVoidOperation::Void
            && $transaction->voided_at !== null
        ) {
            throw new InvalidArgumentException('This Transaction is already voided.');
        }

        if (
            $operation === TransactionVoidOperation::Restore
            && $transaction->voided_at === null
        ) {
            throw new InvalidArgumentException('This Transaction is already active.');
        }
    }
}
