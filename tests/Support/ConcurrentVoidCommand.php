<?php

namespace Tests\Support;

use App\Actions\Ledger\ChangeTransactionVoidState;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionVoidOperation;
use Closure;

final class ConcurrentVoidCommand
{
    /**
     * @return Closure(): array{status: string, revision: int}
     */
    public static function make(
        int $ownerId,
        int $transactionId,
        string $idempotencyKey,
    ): Closure {
        return static function () use ($ownerId, $transactionId, $idempotencyKey): array {
            try {
                $outcome = app(ChangeTransactionVoidState::class)->handle(
                    owner: User::query()->findOrFail($ownerId),
                    transaction: Transaction::query()->findOrFail($transactionId),
                    operation: TransactionVoidOperation::Void,
                    expectedRevision: 1,
                    idempotencyKey: $idempotencyKey,
                );

                return ['status' => 'changed', 'revision' => $outcome->result_revision];
            } catch (StaleTransactionRevision $exception) {
                return ['status' => 'stale', 'revision' => $exception->currentState()['revision']];
            }
        };
    }
}
