<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Models\Category;
use App\Models\OpenClawAuditEvent;
use App\Models\OpenClawConfirmationGrant;
use App\Models\OpenClawPendingOperation;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmOpenClawReceiptBreakdown
{
    public function __construct(
        private UpdateReceiptBreakdownDraft $updateDraft,
        private ConfirmReceiptBreakdown $confirmBreakdown,
    ) {}

    /**
     * @return array{mutation: array{operation: string, resource_type: 'receipt_breakdown', id: int, revision: int, status: string}, replayed: bool}
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

            $existingMutation = $this->existingMutation($serviceKeyId, $schemaVersion, $idempotencyKey);

            if ($existingMutation !== null) {
                return $this->replayOrReject($existingMutation, $owner, $operationDigest);
            }

            if ($pendingOperation === null
                || $pendingOperation->capability !== 'receipt.breakdown.mutation.prepare'
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

            if (! hash_equals($pendingOperation->payload_digest, $payloadDigest)
                || ! hash_equals($pendingOperation->payload_digest, $this->payloadDigest($pendingOperation->payload))) {
                throw new OpenClawConfirmationRejected('confirmation_invalid');
            }

            if ($pendingOperation->confirmed_at !== null) {
                throw new OpenClawConfirmationRejected('confirmation_consumed');
            }

            $this->ensureReferencedStateIsCurrent($owner, $pendingOperation->payload);

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

            $mutation = $this->applyMutation($owner, $pendingOperation->payload);
            $pendingOperation->confirmed_at = now()->toImmutable();
            $pendingOperation->save();

            OpenClawAuditEvent::query()->create([
                'event_kind' => 'mutation',
                'occurred_at' => now(),
                'service_key_id' => $serviceKeyId,
                'schema_version' => $schemaVersion,
                'capability' => 'receipt.breakdown.mutation.confirm',
                'outcome' => 'success',
                'http_status' => Response::HTTP_OK,
                'nonce_digest' => $nonceDigest,
                'request_digest' => $requestDigest,
                'interaction_digest' => $approvalInteractionDigest,
                'resource_type' => 'receipt_breakdown',
                'result_count' => 1,
                'idempotency_key' => $idempotencyKey,
                'operation_digest' => $operationDigest,
                'confirmation_grant_id' => $confirmationGrant->grant_id,
                'domain_action' => $mutation['operation'] === 'update_draft'
                    ? 'receipt_breakdown.update_draft'
                    : 'receipt_breakdown.confirm',
                'resource_id' => $mutation['id'],
                'resource_revision' => $mutation['revision'],
            ]);

            return ['mutation' => $mutation, 'replayed' => false];
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function ensureReferencedStateIsCurrent(User $owner, array $payload): void
    {
        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($payload['transaction_id'])
            ->lockForUpdate()
            ->firstOrFail();

        if ($transaction->revision !== $payload['transaction_revision']) {
            throw new OpenClawConfirmationRejected('stale_revision');
        }

        foreach ($payload['category_revisions'] ?? [] as $categoryRevision) {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryRevision['id'])
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->first();

            if ($category === null || $category->revision !== $categoryRevision['revision']) {
                throw new OpenClawConfirmationRejected('stale_revision');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operation: string, resource_type: 'receipt_breakdown', id: int, revision: int, status: string}
     */
    private function applyMutation(User $owner, array $payload): array
    {
        $breakdown = ReceiptBreakdown::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($payload['receipt_breakdown_id'])
            ->firstOrFail();
        $result = $payload['operation'] === 'update_draft'
            ? $this->updateDraft->handle(
                $owner,
                $breakdown,
                $payload['expected_revision'],
                $payload['line_items'],
            )
            : $this->confirmBreakdown->handle(
                $owner,
                $breakdown,
                $payload['expected_revision'],
            );

        return [
            'operation' => $payload['operation'],
            'resource_type' => 'receipt_breakdown',
            'id' => $result->id,
            'revision' => $result->revision,
            'status' => $result->status,
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
            ->where('capability', 'receipt.breakdown.mutation.confirm')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @return array{mutation: array{operation: string, resource_type: 'receipt_breakdown', id: int, revision: int, status: string}, replayed: true}
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
        $operation = $pendingOperation->payload['operation'] ?? null;

        if (! in_array($operation, ['update_draft', 'confirm_draft'], true)) {
            throw new IdempotencyKeyConflict;
        }

        return [
            'mutation' => [
                'operation' => $operation,
                'resource_type' => 'receipt_breakdown',
                'id' => $mutation->resource_id,
                'revision' => $mutation->resource_revision,
                'status' => $operation === 'confirm_draft' ? 'confirmed' : 'draft',
            ],
            'replayed' => true,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadDigest(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /** @param array<mixed> $value */
    private function canonicalJson(array $value): string
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
