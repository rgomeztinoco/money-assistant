<?php

namespace App\Actions\Ledger;

use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\StaleTransactionRevision;
use App\Models\ReceiptBreakdown;
use App\Models\SpendingNotificationReference;
use App\Models\SuspectedDuplicate;
use App\Models\SuspectedDuplicateReceiptBreakdownMove;
use App\Models\SuspectedDuplicateResolution;
use App\Models\SuspectedDuplicateSourceMove;
use App\Models\Transaction;
use App\Models\User;
use App\ReceiptBreakdownFingerprint;
use App\SourceReferenceSetFingerprint;
use App\SuspectedDuplicateOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ResolveSuspectedDuplicate
{
    public function handle(
        User $owner,
        SuspectedDuplicate $suspectedDuplicate,
        Transaction $survivor,
        int $expectedSuspectedDuplicateRevision,
        int $expectedFirstTransactionRevision,
        int $expectedSecondTransactionRevision,
        string $expectedFirstSourceReferenceFingerprint,
        string $expectedSecondSourceReferenceFingerprint,
        string $expectedFirstReceiptBreakdownFingerprint,
        string $expectedSecondReceiptBreakdownFingerprint,
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

        if (
            ! ctype_xdigit($expectedFirstSourceReferenceFingerprint)
            || Str::length($expectedFirstSourceReferenceFingerprint) !== 64
            || ! ctype_xdigit($expectedSecondSourceReferenceFingerprint)
            || Str::length($expectedSecondSourceReferenceFingerprint) !== 64
        ) {
            throw new InvalidArgumentException('Source reference fingerprints must be valid SHA-256 values.');
        }

        if (
            ! ctype_xdigit($expectedFirstReceiptBreakdownFingerprint)
            || Str::length($expectedFirstReceiptBreakdownFingerprint) !== 64
            || ! ctype_xdigit($expectedSecondReceiptBreakdownFingerprint)
            || Str::length($expectedSecondReceiptBreakdownFingerprint) !== 64
        ) {
            throw new InvalidArgumentException('Receipt Breakdown fingerprints must be valid SHA-256 values.');
        }

        try {
            return DB::transaction(function () use (
                $owner,
                $suspectedDuplicate,
                $survivor,
                $expectedSuspectedDuplicateRevision,
                $expectedFirstTransactionRevision,
                $expectedSecondTransactionRevision,
                $expectedFirstSourceReferenceFingerprint,
                $expectedSecondSourceReferenceFingerprint,
                $expectedFirstReceiptBreakdownFingerprint,
                $expectedSecondReceiptBreakdownFingerprint,
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
                        survivor: $survivor,
                        expectedSuspectedDuplicateRevision: $expectedSuspectedDuplicateRevision,
                        expectedFirstTransactionRevision: $expectedFirstTransactionRevision,
                        expectedSecondTransactionRevision: $expectedSecondTransactionRevision,
                        expectedFirstSourceReferenceFingerprint: $expectedFirstSourceReferenceFingerprint,
                        expectedSecondSourceReferenceFingerprint: $expectedSecondSourceReferenceFingerprint,
                        expectedFirstReceiptBreakdownFingerprint: $expectedFirstReceiptBreakdownFingerprint,
                        expectedSecondReceiptBreakdownFingerprint: $expectedSecondReceiptBreakdownFingerprint,
                    );
                }

                if ($currentSuspectedDuplicate->revision !== $expectedSuspectedDuplicateRevision) {
                    throw new InvalidArgumentException('This Suspected Duplicate changed while you were reviewing it.');
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

                $currentSurvivor = $transactions->get($survivor->getKey());

                if (! $currentSurvivor instanceof Transaction) {
                    throw new InvalidArgumentException('The survivor must belong to this Suspected Duplicate pair.');
                }

                $voidedTransaction = $currentSurvivor->is($firstTransaction)
                    ? $secondTransaction
                    : $firstTransaction;

                $this->ensureResolutionIsCompatible(
                    $currentSuspectedDuplicate,
                    $currentSurvivor,
                    $voidedTransaction,
                );

                $receiptBreakdowns = ReceiptBreakdown::query()
                    ->whereIn('transaction_id', [
                        $currentSurvivor->id,
                        $voidedTransaction->id,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $receiptBreakdownToMove = $receiptBreakdowns
                    ->firstWhere('transaction_id', $voidedTransaction->id);
                $firstReceiptBreakdown = $receiptBreakdowns
                    ->firstWhere('transaction_id', $firstTransaction->id);
                $secondReceiptBreakdown = $receiptBreakdowns
                    ->firstWhere('transaction_id', $secondTransaction->id);

                if (
                    ! hash_equals(
                        $expectedFirstReceiptBreakdownFingerprint,
                        ReceiptBreakdownFingerprint::fromBreakdown($firstReceiptBreakdown),
                    )
                    || ! hash_equals(
                        $expectedSecondReceiptBreakdownFingerprint,
                        ReceiptBreakdownFingerprint::fromBreakdown($secondReceiptBreakdown),
                    )
                ) {
                    throw new InvalidArgumentException('The Receipt Breakdowns changed while you were reviewing this Suspected Duplicate.');
                }

                if ($receiptBreakdownToMove !== null
                    && $receiptBreakdowns->contains('transaction_id', $currentSurvivor->id)) {
                    throw new InvalidArgumentException(
                        'Both Transactions have a Receipt Breakdown. Remove one before resolving this pair.',
                    );
                }

                $firstSourceReferences = SpendingNotificationReference::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereBelongsTo($firstTransaction, 'transaction')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $secondSourceReferences = SpendingNotificationReference::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereBelongsTo($secondTransaction, 'transaction')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (
                    ! hash_equals(
                        $expectedFirstSourceReferenceFingerprint,
                        SourceReferenceSetFingerprint::fromIds($firstSourceReferences->modelKeys()),
                    )
                    || ! hash_equals(
                        $expectedSecondSourceReferenceFingerprint,
                        SourceReferenceSetFingerprint::fromIds($secondSourceReferences->modelKeys()),
                    )
                ) {
                    throw new InvalidArgumentException('The source references changed while you were reviewing this Suspected Duplicate.');
                }

                $resolvedAt = now()->toImmutable();
                $currentSurvivor->revision++;
                $currentSurvivor->save();
                $voidedTransaction->voided_at = $resolvedAt;
                $voidedTransaction->revision++;
                $voidedTransaction->save();

                $currentSuspectedDuplicate->survivor_transaction_id = $currentSurvivor->id;
                $currentSuspectedDuplicate->voided_transaction_id = $voidedTransaction->id;
                $currentSuspectedDuplicate->resolved_at = $resolvedAt;
                $currentSuspectedDuplicate->revision++;
                $currentSuspectedDuplicate->save();

                $resolution = SuspectedDuplicateResolution::create([
                    'user_id' => $owner->getKey(),
                    'suspected_duplicate_id' => $currentSuspectedDuplicate->id,
                    'idempotency_key' => $idempotencyKey,
                    'operation' => SuspectedDuplicateOperation::Resolve,
                    'survivor_transaction_id' => $currentSurvivor->id,
                    'expected_suspected_duplicate_revision' => $expectedSuspectedDuplicateRevision,
                    'expected_first_transaction_revision' => $expectedFirstTransactionRevision,
                    'expected_second_transaction_revision' => $expectedSecondTransactionRevision,
                    'expected_first_source_reference_fingerprint' => $expectedFirstSourceReferenceFingerprint,
                    'expected_second_source_reference_fingerprint' => $expectedSecondSourceReferenceFingerprint,
                    'expected_first_receipt_breakdown_fingerprint' => $expectedFirstReceiptBreakdownFingerprint,
                    'expected_second_receipt_breakdown_fingerprint' => $expectedSecondReceiptBreakdownFingerprint,
                    'result_suspected_duplicate_revision' => $currentSuspectedDuplicate->revision,
                    'result_first_transaction_revision' => $firstTransaction->revision,
                    'result_second_transaction_revision' => $secondTransaction->revision,
                    'result_survivor_transaction_id' => $currentSurvivor->id,
                    'result_voided_transaction_id' => $voidedTransaction->id,
                    'result_resolved_at' => $resolvedAt,
                ]);

                $sourceReferences = $voidedTransaction->is($firstTransaction)
                    ? $firstSourceReferences
                    : $secondSourceReferences;

                foreach ($sourceReferences as $sourceReference) {
                    $sourceReference->transaction_id = $currentSurvivor->id;
                    $sourceReference->save();

                    SuspectedDuplicateSourceMove::create([
                        'suspected_duplicate_resolution_id' => $resolution->id,
                        'spending_notification_reference_id' => $sourceReference->id,
                        'from_transaction_id' => $voidedTransaction->id,
                        'to_transaction_id' => $currentSurvivor->id,
                    ]);
                }

                if ($receiptBreakdownToMove !== null) {
                    $receiptBreakdownToMove->transaction_id = $currentSurvivor->id;
                    $receiptBreakdownToMove->save();

                    SuspectedDuplicateReceiptBreakdownMove::create([
                        'suspected_duplicate_resolution_id' => $resolution->id,
                        'receipt_breakdown_id' => $receiptBreakdownToMove->id,
                        'from_transaction_id' => $voidedTransaction->id,
                        'to_transaction_id' => $currentSurvivor->id,
                    ]);
                }

                return $resolution;
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
                survivor: $survivor,
                expectedSuspectedDuplicateRevision: $expectedSuspectedDuplicateRevision,
                expectedFirstTransactionRevision: $expectedFirstTransactionRevision,
                expectedSecondTransactionRevision: $expectedSecondTransactionRevision,
                expectedFirstSourceReferenceFingerprint: $expectedFirstSourceReferenceFingerprint,
                expectedSecondSourceReferenceFingerprint: $expectedSecondSourceReferenceFingerprint,
                expectedFirstReceiptBreakdownFingerprint: $expectedFirstReceiptBreakdownFingerprint,
                expectedSecondReceiptBreakdownFingerprint: $expectedSecondReceiptBreakdownFingerprint,
            );
        }
    }

    private function ensureResolutionIsCompatible(
        SuspectedDuplicate $suspectedDuplicate,
        Transaction $survivor,
        Transaction $voidedTransaction,
    ): void {
        if ($suspectedDuplicate->resolved_at !== null) {
            throw new InvalidArgumentException('This Suspected Duplicate is already resolved.');
        }

        if ($survivor->voided_at !== null || $voidedTransaction->voided_at !== null) {
            throw new InvalidArgumentException('Both Transactions must be active before duplicate resolution.');
        }

        if (
            $survivor->kind !== $voidedTransaction->kind
            || $survivor->currency !== $voidedTransaction->currency
            || $survivor->amount_minor !== $voidedTransaction->amount_minor
        ) {
            throw new InvalidArgumentException('Only Transactions with the same kind, currency, and amount can be resolved as duplicates.');
        }

        if ($survivor->original_purchase_id !== $voidedTransaction->original_purchase_id) {
            throw new InvalidArgumentException('Transactions with different original purchase relationships cannot be resolved as duplicates.');
        }

        if ($voidedTransaction->linkedRefunds()->exists()) {
            throw new InvalidArgumentException('A purchase with linked Refunds cannot be voided during duplicate resolution.');
        }

        if (SuspectedDuplicate::query()
            ->whereKeyNot($suspectedDuplicate->getKey())
            ->where('survivor_transaction_id', $voidedTransaction->getKey())
            ->whereNotNull('resolved_at')
            ->exists()) {
            throw new InvalidArgumentException('A survivor of another resolved Suspected Duplicate cannot be voided. Keep that survivor or reopen its existing relationship first.');
        }
    }

    private function replayOrReject(
        SuspectedDuplicateResolution $resolution,
        SuspectedDuplicate $suspectedDuplicate,
        Transaction $survivor,
        int $expectedSuspectedDuplicateRevision,
        int $expectedFirstTransactionRevision,
        int $expectedSecondTransactionRevision,
        string $expectedFirstSourceReferenceFingerprint,
        string $expectedSecondSourceReferenceFingerprint,
        string $expectedFirstReceiptBreakdownFingerprint,
        string $expectedSecondReceiptBreakdownFingerprint,
    ): SuspectedDuplicateResolution {
        if (
            $resolution->operation !== SuspectedDuplicateOperation::Resolve
            || $resolution->suspected_duplicate_id !== $suspectedDuplicate->getKey()
            || $resolution->survivor_transaction_id !== $survivor->getKey()
            || $resolution->expected_suspected_duplicate_revision !== $expectedSuspectedDuplicateRevision
            || $resolution->expected_first_transaction_revision !== $expectedFirstTransactionRevision
            || $resolution->expected_second_transaction_revision !== $expectedSecondTransactionRevision
            || $resolution->expected_first_source_reference_fingerprint !== $expectedFirstSourceReferenceFingerprint
            || $resolution->expected_second_source_reference_fingerprint !== $expectedSecondSourceReferenceFingerprint
            || $resolution->expected_first_receipt_breakdown_fingerprint !== $expectedFirstReceiptBreakdownFingerprint
            || $resolution->expected_second_receipt_breakdown_fingerprint !== $expectedSecondReceiptBreakdownFingerprint
        ) {
            throw new IdempotencyKeyConflict;
        }

        return $resolution;
    }
}
