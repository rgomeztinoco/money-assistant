<?php

namespace App\Actions\Reminders;

use App\Models\OpenClawAuditEvent;

final class RecordReminderOpenClawAudit
{
    public function handle(
        string $serviceKeyId,
        int $schemaVersion,
        string $capability,
        string $outcome,
        string $nonceDigest,
        string $requestDigest,
        string $interactionDigest,
    ): void {
        OpenClawAuditEvent::query()->create([
            'occurred_at' => now(),
            'service_key_id' => $serviceKeyId,
            'schema_version' => $schemaVersion,
            'capability' => $capability,
            'outcome' => $outcome,
            'http_status' => 200,
            'nonce_digest' => $nonceDigest,
            'request_digest' => $requestDigest,
            'interaction_digest' => $interactionDigest,
            'resource_type' => 'reminder',
            'result_count' => 1,
        ]);
    }
}
