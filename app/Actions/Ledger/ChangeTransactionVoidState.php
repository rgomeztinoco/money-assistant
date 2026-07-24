<?php

namespace App\Actions\Ledger;

use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleTransactionRevision;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\TransactionStateChange;
use App\Models\User;
use App\TransactionVoidOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ChangeTransactionVoidState
{
    public function handle(
        User $owner,
        Transaction $transaction,
        TransactionVoidOperation $operation,
        int $expectedRevision,
        string $idempotencyKey,
    ): TransactionStateChange {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('The expected Transaction revision must be positive.');
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        try {
            return DB::transaction(function () use (
                $owner,
                $transaction,
                $operation,
                $expectedRevision,
                $idempotencyKey,
            ): TransactionStateChange {
                $currentTransaction = Transaction::query()
                    ->whereKey($transaction->getKey())
                    ->whereBelongsTo($owner, 'owner')
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingStateChange = TransactionStateChange::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingStateChange !== null) {
                    return $this->replayOrReject(
                        stateChange: $existingStateChange,
                        transaction: $currentTransaction,
                        operation: $operation,
                        expectedRevision: $expectedRevision,
                    );
                }

                if ($currentTransaction->revision !== $expectedRevision) {
                    throw StaleTransactionRevision::forVoidStateChange($currentTransaction);
                }

                $this->ensureOperationChangesState($currentTransaction, $operation);

                $currentTransaction->voided_at = $operation === TransactionVoidOperation::Void
                    ? now()->toImmutable()
                    : null;
                $currentTransaction->revision++;
                $currentTransaction->save();

                return TransactionStateChange::create([
                    'user_id' => $owner->getKey(),
                    'transaction_id' => $currentTransaction->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'operation' => $operation,
                    'expected_revision' => $expectedRevision,
                    'result_revision' => $currentTransaction->revision,
                    'result_voided_at' => $currentTransaction->voided_at,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (! Str::contains(
                $exception->getMessage(),
                'transaction_state_changes_user_id_idempotency_key_unique',
            )) {
                throw $exception;
            }

            $existingStateChange = TransactionStateChange::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return $this->replayOrReject(
                stateChange: $existingStateChange,
                transaction: $transaction,
                operation: $operation,
                expectedRevision: $expectedRevision,
            );
        }
    }

    private function replayOrReject(
        TransactionStateChange $stateChange,
        Transaction $transaction,
        TransactionVoidOperation $operation,
        int $expectedRevision,
    ): TransactionStateChange {
        if (
            $stateChange->transaction_id !== $transaction->getKey()
            || $stateChange->operation !== $operation
            || $stateChange->expected_revision !== $expectedRevision
        ) {
            throw new IdempotencyKeyConflict;
        }

        return $stateChange;
    }

    private function ensureOperationChangesState(
        Transaction $transaction,
        TransactionVoidOperation $operation,
    ): void {
        if (
            $operation === TransactionVoidOperation::Void
            && SuspectedDuplicate::query()
                ->where('survivor_transaction_id', $transaction->getKey())
                ->whereNotNull('resolved_at')
                ->exists()
        ) {
            throw new InvalidArgumentException('Reopen the Suspected Duplicate relationship before voiding its survivor.');
        }

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

        if (
            $operation === TransactionVoidOperation::Restore
            && SuspectedDuplicate::query()
                ->where('voided_transaction_id', $transaction->getKey())
                ->whereNotNull('resolved_at')
                ->exists()
        ) {
            throw new InvalidArgumentException('Reopen the Suspected Duplicate relationship to restore this Transaction and its source references.');
        }
    }
}
