<?php

namespace App\Actions\OpenClaw;

use App\Actions\Categorization\DeleteCategory;
use App\Actions\ReceiptReconciliation\DiscardReceiptBreakdownDraft;
use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\OpenClawConfirmationRejected;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\OpenClawPendingOperation;
use App\Models\ReceiptBreakdown;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteFinancialDeletion
{
    public function __construct(
        private DeleteCategory $deleteCategory,
        private DiscardReceiptBreakdownDraft $discardReceiptBreakdownDraft,
        private ComputeOpenClawPayloadDigest $computePayloadDigest,
        private RecordWebApprovedOperation $recordWebApprovedOperation,
    ) {}

    public function handle(
        User $owner,
        string $operationId,
        int $expectedRevision,
        string $payloadDigest,
        string $webApprovalDigest,
    ): string {
        return DB::transaction(function () use (
            $owner,
            $operationId,
            $expectedRevision,
            $payloadDigest,
            $webApprovalDigest,
        ): string {
            $owner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $operation = OpenClawPendingOperation::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('operation_id', $operationId)
                ->where('capability', 'financial.deletion.prepare')
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUsable($operation, $expectedRevision, $payloadDigest);
            $payload = $this->deletionPayload($operation);
            try {
                $redirectRoute = $this->deleteTarget($owner, $payload);
            } catch (CategoryOperationBlocked|StaleCategoryRevision|StaleReceiptBreakdownRevision|ModelNotFoundException|ValidationException) {
                throw new OpenClawConfirmationRejected('stale_revision');
            }
            $this->recordWebApprovedOperation->handle(
                operation: $operation,
                payloadDigest: $payloadDigest,
                webApprovalDigest: $webApprovalDigest,
                audit: new WebApprovedOperationAudit(
                    capability: 'financial.deletion.complete',
                    httpStatus: 302,
                    resourceType: $payload['resource_type'],
                    domainAction: 'financial.deletion',
                    resourceId: $payload['resource_id'],
                    resourceRevision: $payload['expected_revision'],
                ),
            );

            return $redirectRoute;
        }, 3);
    }

    /** @param array{resource_type: 'category'|'receipt_breakdown', resource_id: int, expected_revision: int} $payload */
    private function deleteTarget(User $owner, array $payload): string
    {
        if ($payload['resource_type'] === 'category') {
            $this->deleteCategory->handle(
                $owner,
                (int) $payload['resource_id'],
                (int) $payload['expected_revision'],
            );

            return 'categories.index';
        }

        $breakdown = ReceiptBreakdown::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($payload['resource_id'])
            ->firstOrFail();
        $this->discardReceiptBreakdownDraft->handle(
            $owner,
            $breakdown,
            (int) $payload['expected_revision'],
        );

        return 'transactions.index';
    }

    private function assertUsable(
        OpenClawPendingOperation $operation,
        int $expectedRevision,
        string $payloadDigest,
    ): void {
        if ($operation->revision !== $expectedRevision
            || $operation->canceled_at !== null) {
            throw new OpenClawConfirmationRejected('stale_revision');
        }

        if (! hash_equals($operation->payload_digest, $payloadDigest)
            || ! hash_equals(
                $operation->payload_digest,
                $this->computePayloadDigest->handle($operation->payload),
            )) {
            throw new OpenClawConfirmationRejected('confirmation_invalid');
        }

        if ($operation->expires_at->isPast()) {
            throw new OpenClawConfirmationRejected('confirmation_expired');
        }

        if ($operation->confirmed_at !== null) {
            throw new OpenClawConfirmationRejected('confirmation_consumed');
        }
    }

    /** @return array{resource_type: 'category'|'receipt_breakdown', resource_id: int, expected_revision: int} */
    private function deletionPayload(OpenClawPendingOperation $operation): array
    {
        $payload = $operation->payload;
        $resourceType = $payload['resource_type'] ?? null;
        $resourceId = $payload['resource_id'] ?? null;
        $expectedRevision = $payload['expected_revision'] ?? null;

        if (! is_string($resourceType)
            || ! is_int($resourceId)
            || ! is_int($expectedRevision)) {
            throw new OpenClawConfirmationRejected('confirmation_invalid');
        }

        return [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'expected_revision' => $expectedRevision,
        ];
    }
}
