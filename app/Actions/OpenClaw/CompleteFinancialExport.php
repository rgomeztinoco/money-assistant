<?php

namespace App\Actions\OpenClaw;

use App\Exceptions\OpenClawConfirmationRejected;
use App\Models\OpenClawPendingOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CompleteFinancialExport
{
    public function __construct(
        private BuildFinancialExport $buildFinancialExport,
        private ComputeOpenClawPayloadDigest $computePayloadDigest,
        private RecordWebApprovedOperation $recordWebApprovedOperation,
    ) {}

    public function handle(
        User $owner,
        string $operationId,
        int $expectedRevision,
        string $payloadDigest,
        string $webApprovalDigest,
    ): FinancialExportArtifact {
        return DB::transaction(function () use (
            $owner,
            $operationId,
            $expectedRevision,
            $payloadDigest,
            $webApprovalDigest,
        ): FinancialExportArtifact {
            $owner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $operation = OpenClawPendingOperation::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('operation_id', $operationId)
                ->where('capability', 'financial.export.prepare')
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUsable($operation, $expectedRevision, $payloadDigest);
            $export = $this->buildFinancialExport->handle($owner);

            if (! hash_equals(
                $this->ownerStateDigest($operation),
                $export->digest,
            )) {
                throw new OpenClawConfirmationRejected('stale_revision');
            }

            $this->recordWebApprovedOperation->handle(
                operation: $operation,
                payloadDigest: $payloadDigest,
                webApprovalDigest: $webApprovalDigest,
                audit: new WebApprovedOperationAudit(
                    capability: 'financial.export.complete',
                    httpStatus: 200,
                    resourceType: 'financial_export',
                    domainAction: 'financial.export',
                    resourceId: $owner->id,
                    resourceRevision: $operation->revision,
                ),
            );

            return $export;
        }, 3);
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

    private function ownerStateDigest(OpenClawPendingOperation $operation): string
    {
        $payload = $operation->payload;
        $ownerStateDigest = $payload['owner_state_digest'] ?? null;

        if (! is_string($ownerStateDigest)) {
            throw new OpenClawConfirmationRejected('confirmation_invalid');
        }

        return $ownerStateDigest;
    }
}
