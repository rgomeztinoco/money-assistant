<?php

namespace App\Actions\Reminders;

use App\Models\ReminderDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RecordReminderChannelDelivery
{
    public function __construct(private RecordReminderOpenClawAudit $recordAudit) {}

    /**
     * @return array{delivery: ReminderDelivery, replayed: bool}|null
     */
    public function handle(
        User $owner,
        string $eventId,
        string $serviceKeyId,
        int $schemaVersion,
        string $interactionDigest,
        string $nonceDigest,
        string $requestDigest,
    ): ?array {
        return DB::transaction(function () use (
            $owner,
            $eventId,
            $serviceKeyId,
            $schemaVersion,
            $interactionDigest,
            $nonceDigest,
            $requestDigest,
        ): ?array {
            $delivery = ReminderDelivery::query()
                ->admittedOpenClawEvent()
                ->whereKey($eventId)
                ->whereHas('reminder', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return null;
            }

            $replayed = $delivery->delivered_at !== null;

            if (! $replayed) {
                $delivery->forceFill(['delivered_at' => now()])->save();
            }

            $this->recordAudit->handle(
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                capability: 'reminder.delivery.record',
                outcome: $replayed ? 'idempotent_replay' : 'success',
                nonceDigest: $nonceDigest,
                requestDigest: $requestDigest,
                interactionDigest: $interactionDigest,
            );

            return ['delivery' => $delivery, 'replayed' => $replayed];
        });
    }
}
