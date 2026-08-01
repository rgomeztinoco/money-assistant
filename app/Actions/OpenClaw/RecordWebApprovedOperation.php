<?php

namespace App\Actions\OpenClaw;

use App\Models\OpenClawAuditEvent;
use App\Models\OpenClawConfirmationGrant;
use App\Models\OpenClawPendingOperation;
use Illuminate\Support\Str;

final class RecordWebApprovedOperation
{
    public function handle(
        OpenClawPendingOperation $operation,
        string $payloadDigest,
        string $webApprovalDigest,
        WebApprovedOperationAudit $audit,
    ): void {
        $grantId = (string) Str::uuid();

        OpenClawConfirmationGrant::query()->create([
            'grant_id' => $grantId,
            'open_claw_pending_operation_id' => $operation->id,
            'user_id' => $operation->user_id,
            'service_key_id' => $operation->service_key_id,
            'schema_version' => $operation->schema_version,
            'payload_digest' => $operation->payload_digest,
            'pending_operation_revision' => $operation->revision,
            'approval_interaction_digest' => $webApprovalDigest,
            'approval_occurred_at' => now(),
            'expires_at' => $operation->expires_at,
            'consumed_at' => now(),
        ]);
        $operation->confirmed_at = now();
        $operation->save();

        OpenClawAuditEvent::query()->create([
            'occurred_at' => now(),
            'service_key_id' => $operation->service_key_id,
            'schema_version' => $operation->schema_version,
            'capability' => $audit->capability,
            'outcome' => 'success',
            'http_status' => $audit->httpStatus,
            'nonce_digest' => hash('sha256', "web\0{$operation->operation_id}"),
            'request_digest' => hash('sha256', $payloadDigest),
            'interaction_digest' => $webApprovalDigest,
            'resource_type' => $audit->resourceType,
            'result_count' => 1,
            'event_kind' => 'mutation',
            'idempotency_key' => $operation->idempotency_key,
            'operation_digest' => $operation->payload_digest,
            'confirmation_grant_id' => $grantId,
            'domain_action' => $audit->domainAction,
            'resource_id' => $audit->resourceId,
            'resource_revision' => $audit->resourceRevision,
        ]);
    }
}
