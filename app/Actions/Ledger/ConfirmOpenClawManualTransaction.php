<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Models\OpenClawAuditEvent;
use App\Models\OpenClawConfirmationGrant;
use App\Models\OpenClawPendingOperation;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmOpenClawManualTransaction
{
    public function __construct(
        private RecordManualTransaction $recordManualTransaction,
    ) {}

    /**
     * @return array{
     *     transaction: array{
     *         id: int,
     *         revision: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         status: string
     *     },
     *     replayed: bool
     * }
     *
     * @throws JsonException
     */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $conversationId,
        string $approvalInteractionDigest,
        CarbonImmutable $approvalOccurredAt,
        string $pendingOperationId,
        int $pendingOperationRevision,
        string $payloadDigest,
        string $idempotencyKey,
        string $nonceDigest,
        string $requestDigest,
    ): array {
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The idempotency key must be a valid UUID.');
        }

        $operationDigest = hash('sha256', json_encode([
            'pending_operation_id' => $pendingOperationId,
            'pending_operation_revision' => $pendingOperationRevision,
            'payload_digest' => $payloadDigest,
            'approval_interaction_digest' => $approvalInteractionDigest,
            'approval_occurred_at' => $approvalOccurredAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use (
                $owner,
                $serviceKeyId,
                $schemaVersion,
                $conversationId,
                $approvalInteractionDigest,
                $approvalOccurredAt,
                $pendingOperationId,
                $pendingOperationRevision,
                $payloadDigest,
                $idempotencyKey,
                $nonceDigest,
                $requestDigest,
                $operationDigest,
            ): array {
                $existingMutation = $this->existingMutation(
                    $serviceKeyId,
                    $schemaVersion,
                    $idempotencyKey,
                );

                if ($existingMutation !== null) {
                    return $this->replayOrReject($existingMutation, $owner, $operationDigest);
                }

                $pendingOperation = OpenClawPendingOperation::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->where('operation_id', $pendingOperationId)
                    ->lockForUpdate()
                    ->first();

                $existingMutation = $this->existingMutation(
                    $serviceKeyId,
                    $schemaVersion,
                    $idempotencyKey,
                );

                if ($existingMutation !== null) {
                    return $this->replayOrReject($existingMutation, $owner, $operationDigest);
                }

                if ($pendingOperation === null
                    || ! hash_equals($pendingOperation->service_key_id, $serviceKeyId)
                    || $pendingOperation->schema_version !== $schemaVersion
                    || ! hash_equals($pendingOperation->conversation_digest, hash('sha256', $conversationId))) {
                    throw new OpenClawConfirmationRejected('confirmation_invalid');
                }

                if (hash_equals(
                    $pendingOperation->preparation_interaction_digest,
                    $approvalInteractionDigest,
                ) || $approvalOccurredAt->lessThan($pendingOperation->preparation_occurred_at)) {
                    throw new OpenClawConfirmationRejected('approval_message_required');
                }

                if ($pendingOperation->expires_at->lessThanOrEqualTo(now())) {
                    throw new OpenClawConfirmationRejected('confirmation_expired');
                }

                if ($pendingOperation->revision !== $pendingOperationRevision
                    || $pendingOperation->canceled_at !== null) {
                    throw new OpenClawConfirmationRejected('stale_revision');
                }

                $payload = $pendingOperation->payload;
                $currentPayloadDigest = hash('sha256', json_encode([
                    'occurred_on' => $payload['occurred_on'],
                    'amount_minor' => $payload['amount_minor'],
                    'currency' => $payload['currency'],
                    'kind' => $payload['kind'],
                    'merchant_description' => $payload['merchant_description'],
                ], JSON_THROW_ON_ERROR));

                if (! hash_equals($pendingOperation->payload_digest, $payloadDigest)
                    || ! hash_equals($pendingOperation->payload_digest, $currentPayloadDigest)) {
                    throw new OpenClawConfirmationRejected('confirmation_invalid');
                }

                if ($pendingOperation->confirmed_at !== null) {
                    throw new OpenClawConfirmationRejected('confirmation_consumed');
                }

                $confirmationGrant = OpenClawConfirmationGrant::query()->create([
                    'grant_id' => (string) Str::uuid(),
                    'open_claw_pending_operation_id' => $pendingOperation->getKey(),
                    'user_id' => $owner->getKey(),
                    'service_key_id' => $serviceKeyId,
                    'schema_version' => $schemaVersion,
                    'payload_digest' => $pendingOperation->payload_digest,
                    'pending_operation_revision' => $pendingOperation->revision,
                    'approval_interaction_digest' => $approvalInteractionDigest,
                    'approval_occurred_at' => $approvalOccurredAt,
                    'expires_at' => $pendingOperation->expires_at,
                    'consumed_at' => now(),
                ]);

                $transaction = $this->recordManualTransaction->handle(
                    owner: $owner,
                    occurredOn: CarbonImmutable::createFromFormat('!Y-m-d', $payload['occurred_on']),
                    amountMinor: $payload['amount_minor'],
                    currency: Currency::from($payload['currency']),
                    kind: TransactionKind::from($payload['kind']),
                    merchantDescription: $payload['merchant_description'],
                );

                $pendingOperation->confirmed_at = now()->toImmutable();
                $pendingOperation->save();

                OpenClawAuditEvent::query()->create([
                    'event_kind' => 'mutation',
                    'occurred_at' => now(),
                    'service_key_id' => $serviceKeyId,
                    'schema_version' => $schemaVersion,
                    'capability' => 'transaction.manual.confirm',
                    'outcome' => 'success',
                    'http_status' => Response::HTTP_OK,
                    'nonce_digest' => $nonceDigest,
                    'request_digest' => $requestDigest,
                    'interaction_digest' => $approvalInteractionDigest,
                    'resource_type' => 'transaction',
                    'result_count' => 1,
                    'idempotency_key' => $idempotencyKey,
                    'operation_digest' => $operationDigest,
                    'confirmation_grant_id' => $confirmationGrant->grant_id,
                    'domain_action' => 'transaction.record_manual',
                    'resource_id' => $transaction->id,
                    'resource_revision' => $transaction->revision,
                ]);

                return [
                    'transaction' => $this->transactionOutcome(
                        $transaction->id,
                        $transaction->revision,
                        $payload,
                    ),
                    'replayed' => false,
                ];
            }, 3);
        } catch (QueryException $exception) {
            if (! Str::contains(
                $exception->getMessage(),
                'open_claw_audit_events_mutation_idempotency_unique',
            )) {
                throw $exception;
            }

            $existingMutation = $this->existingMutation(
                $serviceKeyId,
                $schemaVersion,
                $idempotencyKey,
            );

            if ($existingMutation === null) {
                throw $exception;
            }

            return $this->replayOrReject($existingMutation, $owner, $operationDigest);
        }
    }

    private function existingMutation(
        string $serviceKeyId,
        int $schemaVersion,
        string $idempotencyKey,
    ): ?OpenClawAuditEvent {
        return OpenClawAuditEvent::query()
            ->where('event_kind', 'mutation')
            ->where('service_key_id', $serviceKeyId)
            ->where('schema_version', $schemaVersion)
            ->where('capability', 'transaction.manual.confirm')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @return array{
     *     transaction: array{
     *         id: int,
     *         revision: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         status: string
     *     },
     *     replayed: true
     * }
     */
    private function replayOrReject(
        OpenClawAuditEvent $mutation,
        User $owner,
        string $operationDigest,
    ): array {
        if ($mutation->operation_digest === null
            || ! hash_equals($mutation->operation_digest, $operationDigest)
            || $mutation->resource_id === null
            || $mutation->resource_revision === null
            || $mutation->confirmation_grant_id === null) {
            throw new IdempotencyKeyConflict;
        }

        $confirmationGrant = OpenClawConfirmationGrant::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('grant_id', $mutation->confirmation_grant_id)
            ->firstOrFail();
        $pendingOperation = OpenClawPendingOperation::query()
            ->whereKey($confirmationGrant->open_claw_pending_operation_id)
            ->firstOrFail();

        return [
            'transaction' => $this->transactionOutcome(
                $mutation->resource_id,
                $mutation->resource_revision,
                $pendingOperation->payload,
            ),
            'replayed' => true,
        ];
    }

    /**
     * @param  array{occurred_on: string, amount_minor: int, currency: string, kind: string, merchant_description: string}  $payload
     * @return array{id: int, revision: int, occurred_on: string, amount_minor: string, currency: string, kind: string, merchant_description: string, status: string}
     */
    private function transactionOutcome(int $id, int $revision, array $payload): array
    {
        return [
            'id' => $id,
            'revision' => $revision,
            'occurred_on' => $payload['occurred_on'],
            'amount_minor' => (string) $payload['amount_minor'],
            'currency' => $payload['currency'],
            'kind' => $payload['kind'],
            'merchant_description' => $payload['merchant_description'],
            'status' => 'active',
        ];
    }
}
