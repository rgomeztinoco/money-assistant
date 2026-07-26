<?php

namespace App\Actions\Categorization;

use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Models\Category;
use App\Models\OpenClawAuditEvent;
use App\Models\OpenClawConfirmationGrant;
use App\Models\OpenClawPendingOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmOpenClawCategorization
{
    public function __construct(
        private CreateCategory $createCategory,
        private UpdateCategory $updateCategory,
        private RetireCategory $retireCategory,
        private ReactivateCategory $reactivateCategory,
        private AssignCategoryToTransaction $assignCategoryToTransaction,
    ) {}

    /**
     * @return array{mutation: array{operation: string, resource_type: string, id: int, revision: int}, replayed: bool}
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
                $existingMutation = $this->existingMutation($serviceKeyId, $schemaVersion, $idempotencyKey);

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
                    || $pendingOperation->capability !== 'category.mutation.prepare'
                    || ! hash_equals($pendingOperation->service_key_id, $serviceKeyId)
                    || $pendingOperation->schema_version !== $schemaVersion
                    || ! hash_equals($pendingOperation->conversation_digest, hash('sha256', $conversationId))) {
                    throw new OpenClawConfirmationRejected('confirmation_invalid');
                }

                if (hash_equals($pendingOperation->preparation_interaction_digest, $approvalInteractionDigest)
                    || $approvalOccurredAt->lessThan($pendingOperation->preparation_occurred_at)) {
                    throw new OpenClawConfirmationRejected('approval_message_required');
                }

                if ($pendingOperation->expires_at->lessThanOrEqualTo(now())) {
                    throw new OpenClawConfirmationRejected('confirmation_expired');
                }

                if ($pendingOperation->revision !== $pendingOperationRevision
                    || $pendingOperation->canceled_at !== null) {
                    throw new OpenClawConfirmationRejected('stale_revision');
                }

                $payload = $this->categorizationPayload($pendingOperation->payload);
                $currentPayloadDigest = $this->payloadDigest($payload);

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

                $mutation = $this->applyMutation($owner, $payload);
                $pendingOperation->confirmed_at = now()->toImmutable();
                $pendingOperation->save();

                OpenClawAuditEvent::query()->create([
                    'event_kind' => 'mutation',
                    'occurred_at' => now(),
                    'service_key_id' => $serviceKeyId,
                    'schema_version' => $schemaVersion,
                    'capability' => 'category.mutation.confirm',
                    'outcome' => 'success',
                    'http_status' => Response::HTTP_OK,
                    'nonce_digest' => $nonceDigest,
                    'request_digest' => $requestDigest,
                    'interaction_digest' => $approvalInteractionDigest,
                    'resource_type' => $mutation['resource_type'],
                    'result_count' => 1,
                    'idempotency_key' => $idempotencyKey,
                    'operation_digest' => $operationDigest,
                    'confirmation_grant_id' => $confirmationGrant->grant_id,
                    'domain_action' => $this->domainAction($mutation['operation']),
                    'resource_id' => $mutation['id'],
                    'resource_revision' => $mutation['revision'],
                ]);

                return ['mutation' => $mutation, 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            if (! Str::contains($exception->getMessage(), 'open_claw_audit_events_mutation_idempotency_unique')) {
                throw $exception;
            }

            $existingMutation = $this->existingMutation($serviceKeyId, $schemaVersion, $idempotencyKey);

            if ($existingMutation === null) {
                throw $exception;
            }

            return $this->replayOrReject($existingMutation, $owner, $operationDigest);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operation: string, resource_type: string, id: int, revision: int}
     */
    private function applyMutation(User $owner, array $payload): array
    {
        $operation = $payload['operation'];
        $resource = match ($operation) {
            'create' => $this->createCategory->handle(
                $owner,
                $payload['name'],
                $payload['parent_id'],
                $payload['description'],
                $payload['examples'],
                $payload['parent_revision'],
            ),
            'update' => $this->updateCategory->handle(
                $owner,
                $payload['category_id'],
                $payload['expected_revision'],
                $payload['name'],
                $payload['parent_id'],
                $payload['description'],
                $payload['examples'],
                $payload['parent_revision'],
            ),
            'retire' => $this->retireCategory->handle(
                $owner,
                $payload['category_id'],
                $payload['expected_revision'],
            ),
            'reactivate' => $this->reactivateCategory->handle(
                $owner,
                $payload['category_id'],
                $payload['expected_revision'],
                $payload['parent_revision'],
            ),
            default => $this->assignCategoryToTransaction->handle(
                $owner,
                $payload['transaction_id'],
                $payload['expected_revision'],
                $payload['category_id'],
                $payload['category_revision'],
            ),
        };

        return [
            'operation' => $operation,
            'resource_type' => $resource instanceof Category ? 'category' : 'transaction',
            'id' => $resource->id,
            'revision' => $resource->revision,
        ];
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
            ->where('capability', 'category.mutation.confirm')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @return array{mutation: array{operation: string, resource_type: string, id: int, revision: int}, replayed: true}
     */
    private function replayOrReject(
        OpenClawAuditEvent $mutation,
        User $owner,
        string $operationDigest,
    ): array {
        if ($mutation->operation_digest === null
            || ! hash_equals($mutation->operation_digest, $operationDigest)
            || $mutation->resource_type === null
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
        $operation = $pendingOperation->payload['operation'] ?? null;

        if (! is_string($operation)) {
            throw new IdempotencyKeyConflict;
        }

        return [
            'mutation' => [
                'operation' => $operation,
                'resource_type' => $mutation->resource_type,
                'id' => $mutation->resource_id,
                'revision' => $mutation->resource_revision,
            ],
            'replayed' => true,
        ];
    }

    private function domainAction(string $operation): string
    {
        return match ($operation) {
            'create' => 'category.create',
            'update' => 'category.update',
            'retire' => 'category.retire',
            'reactivate' => 'category.reactivate',
            default => 'transaction.assign_category',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function categorizationPayload(array $payload): array
    {
        if (! is_string($payload['operation'] ?? null)) {
            throw new OpenClawConfirmationRejected('confirmation_invalid');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function payloadDigest(array $payload): string
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value) && ! array_is_list($value)) {
                ksort($value);
            }
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
