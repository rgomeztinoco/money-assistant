<?php

namespace App\Actions\Ledger;

use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleTransactionRevision;
use App\Models\SpendingNotificationReference;
use App\Models\SuspectedDuplicate;
use App\Models\SuspectedDuplicateResolution;
use App\Models\Transaction;
use App\Models\User;
use App\SuspectedDuplicateOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReopenSuspectedDuplicate
{
    public function handle(
        User $owner,
        SuspectedDuplicate $suspectedDuplicate,
        int $expectedSuspectedDuplicateRevision,
        int $expectedFirstTransactionRevision,
        int $expectedSecondTransactionRevision,
        string $idempotencyKey,
    ): SuspectedDuplicateResolution {
        if (
            $expectedSuspectedDuplicateRevision < 1
            || $expectedFirstTransactionRevision < 1
            || $expectedSecondTransactionRevision < 1
        ) {
            throw new InvalidArgumentException('Expected revisions must be positive.');
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        try {
            return DB::transaction(function () use (
                $owner,
                $suspectedDuplicate,
                $expectedSuspectedDuplicateRevision,
                $expectedFirstTransactionRevision,
                $expectedSecondTransactionRevision,
                $idempotencyKey,
            ): SuspectedDuplicateResolution {
                $currentSuspectedDuplicate = SuspectedDuplicate::query()
                    ->whereKey($suspectedDuplicate->getKey())
                    ->whereBelongsTo($owner, 'owner')
                    ->lockForUpdate()
                    ->firstOrFail();
                $existingResolution = SuspectedDuplicateResolution::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingResolution !== null) {
                    return $this->replayOrReject(
                        resolution: $existingResolution,
                        suspectedDuplicate: $currentSuspectedDuplicate,
                        expectedSuspectedDuplicateRevision: $expectedSuspectedDuplicateRevision,
                        expectedFirstTransactionRevision: $expectedFirstTransactionRevision,
                        expectedSecondTransactionRevision: $expectedSecondTransactionRevision,
                    );
                }

                if ($currentSuspectedDuplicate->revision !== $expectedSuspectedDuplicateRevision) {
                    throw new InvalidArgumentException('This Suspected Duplicate changed while you were reviewing it.');
                }

                if (
                    $currentSuspectedDuplicate->resolved_at === null
                    || $currentSuspectedDuplicate->survivor_transaction_id === null
                    || $currentSuspectedDuplicate->voided_transaction_id === null
                ) {
                    throw new InvalidArgumentException('This Suspected Duplicate is already open for review.');
                }

                $transactions = Transaction::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey([
                        $currentSuspectedDuplicate->first_transaction_id,
                        $currentSuspectedDuplicate->second_transaction_id,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $firstTransaction = $transactions->get($currentSuspectedDuplicate->first_transaction_id);
                $secondTransaction = $transactions->get($currentSuspectedDuplicate->second_transaction_id);

                if (! $firstTransaction instanceof Transaction || ! $secondTransaction instanceof Transaction) {
                    throw new InvalidArgumentException('Both Suspected Duplicate Transactions must belong to the owner.');
                }

                if ($firstTransaction->revision !== $expectedFirstTransactionRevision) {
                    throw StaleTransactionRevision::fromTransaction($firstTransaction);
                }

                if ($secondTransaction->revision !== $expectedSecondTransactionRevision) {
                    throw StaleTransactionRevision::fromTransaction($secondTransaction);
                }

                $survivor = $transactions->get($currentSuspectedDuplicate->survivor_transaction_id);
                $voidedTransaction = $transactions->get($currentSuspectedDuplicate->voided_transaction_id);

                if (
                    ! $survivor instanceof Transaction
                    || ! $voidedTransaction instanceof Transaction
                    || $survivor->voided_at !== null
                    || $voidedTransaction->voided_at === null
                ) {
                    throw new InvalidArgumentException('The resolved Transaction states changed before the relationship could be reopened.');
                }

                $resolveOutcome = SuspectedDuplicateResolution::query()
                    ->whereBelongsTo($currentSuspectedDuplicate)
                    ->where('operation', SuspectedDuplicateOperation::Resolve)
                    ->where(
                        'result_suspected_duplicate_revision',
                        $currentSuspectedDuplicate->revision,
                    )
                    ->with('sourceMoves')
                    ->lockForUpdate()
                    ->firstOrFail();

                foreach ($resolveOutcome->sourceMoves as $sourceMove) {
                    $sourceReference = SpendingNotificationReference::query()
                        ->whereKey($sourceMove->spending_notification_reference_id)
                        ->whereBelongsTo($owner, 'owner')
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($sourceReference->transaction_id !== $sourceMove->to_transaction_id) {
                        throw new InvalidArgumentException('A moved source reference changed before the relationship could be reopened.');
                    }

                    $sourceReference->transaction_id = $sourceMove->from_transaction_id;
                    $sourceReference->save();
                }

                $survivor->revision++;
                $survivor->save();
                $voidedTransaction->voided_at = null;
                $voidedTransaction->revision++;
                $voidedTransaction->save();

                $currentSuspectedDuplicate->survivor_transaction_id = null;
                $currentSuspectedDuplicate->voided_transaction_id = null;
                $currentSuspectedDuplicate->resolved_at = null;
                $currentSuspectedDuplicate->revision++;
                $currentSuspectedDuplicate->save();

                return SuspectedDuplicateResolution::create([
                    'user_id' => $owner->getKey(),
                    'suspected_duplicate_id' => $currentSuspectedDuplicate->id,
                    'idempotency_key' => $idempotencyKey,
                    'operation' => SuspectedDuplicateOperation::Reopen,
                    'survivor_transaction_id' => null,
                    'expected_suspected_duplicate_revision' => $expectedSuspectedDuplicateRevision,
                    'expected_first_transaction_revision' => $expectedFirstTransactionRevision,
                    'expected_second_transaction_revision' => $expectedSecondTransactionRevision,
                    'expected_first_source_reference_fingerprint' => null,
                    'expected_second_source_reference_fingerprint' => null,
                    'result_suspected_duplicate_revision' => $currentSuspectedDuplicate->revision,
                    'result_first_transaction_revision' => $firstTransaction->revision,
                    'result_second_transaction_revision' => $secondTransaction->revision,
                    'result_survivor_transaction_id' => null,
                    'result_voided_transaction_id' => null,
                    'result_resolved_at' => null,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (! Str::contains(
                $exception->getMessage(),
                'suspected_duplicate_resolutions_owner_idempotency_unique',
            )) {
                throw $exception;
            }

            $existingResolution = SuspectedDuplicateResolution::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return $this->replayOrReject(
                resolution: $existingResolution,
                suspectedDuplicate: $suspectedDuplicate,
                expectedSuspectedDuplicateRevision: $expectedSuspectedDuplicateRevision,
                expectedFirstTransactionRevision: $expectedFirstTransactionRevision,
                expectedSecondTransactionRevision: $expectedSecondTransactionRevision,
            );
        }
    }

    private function replayOrReject(
        SuspectedDuplicateResolution $resolution,
        SuspectedDuplicate $suspectedDuplicate,
        int $expectedSuspectedDuplicateRevision,
        int $expectedFirstTransactionRevision,
        int $expectedSecondTransactionRevision,
    ): SuspectedDuplicateResolution {
        if (
            $resolution->operation !== SuspectedDuplicateOperation::Reopen
            || $resolution->suspected_duplicate_id !== $suspectedDuplicate->getKey()
            || $resolution->expected_suspected_duplicate_revision !== $expectedSuspectedDuplicateRevision
            || $resolution->expected_first_transaction_revision !== $expectedFirstTransactionRevision
            || $resolution->expected_second_transaction_revision !== $expectedSecondTransactionRevision
        ) {
            throw new IdempotencyKeyConflict;
        }

        return $resolution;
    }
}
