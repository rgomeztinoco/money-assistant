<?php

namespace App\Actions\Reminders;

use App\Exceptions\IdempotencyKeyConflict;
use App\Models\Reminder;
use App\Models\ReminderLifecycleEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RespondToReminder
{
    public function __construct(private RecordReminderOpenClawAudit $recordAudit) {}

    /**
     * @return array{reminder: Reminder, replayed: bool}
     */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $interactionDigest,
        string $nonceDigest,
        string $requestDigest,
        string $idempotencyKey,
        int $reminderId,
        string $action,
        ?CarbonImmutable $snoozedUntil = null,
    ): array {
        if (! in_array($action, ['acknowledge', 'snooze', 'dismiss'], true)) {
            throw new InvalidArgumentException('The Reminder response is invalid.');
        }

        if (($action === 'snooze') !== ($snoozedUntil !== null)
            || ($snoozedUntil !== null && ! $snoozedUntil->isFuture())) {
            throw new InvalidArgumentException('A future snooze time is required.');
        }

        $payloadDigest = hash('sha256', json_encode([
            'reminder_id' => $reminderId,
            'action' => $action,
            'snoozed_until' => $snoozedUntil?->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $owner,
            $serviceKeyId,
            $schemaVersion,
            $interactionDigest,
            $nonceDigest,
            $requestDigest,
            $idempotencyKey,
            $reminderId,
            $action,
            $snoozedUntil,
            $payloadDigest,
        ): array {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $existing = ReminderLifecycleEvent::query()
                ->where('service_key_id', $serviceKeyId)
                ->where('schema_version', $schemaVersion)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_digest, $payloadDigest)) {
                    throw new IdempotencyKeyConflict;
                }

                $reminder = Reminder::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->findOrFail($existing->reminder_id);

                $this->recordAudit->handle(
                    serviceKeyId: $serviceKeyId,
                    schemaVersion: $schemaVersion,
                    capability: 'reminder.respond',
                    outcome: 'idempotent_replay',
                    nonceDigest: $nonceDigest,
                    requestDigest: $requestDigest,
                    interactionDigest: $interactionDigest,
                );

                return ['reminder' => $reminder, 'replayed' => true];
            }

            $reminder = Reminder::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->find($reminderId);

            if ($reminder === null) {
                throw (new ModelNotFoundException)->setModel(Reminder::class, [$reminderId]);
            }

            if ($reminder->dismissed_at !== null || $reminder->resolved_at !== null) {
                throw new InvalidArgumentException('The Reminder occurrence is closed.');
            }

            $attributes = match ($action) {
                'acknowledge' => ['acknowledged_at' => $reminder->acknowledged_at ?? now()],
                'snooze' => [
                    'snoozed_until' => $snoozedUntil,
                    'scheduled_for' => $snoozedUntil,
                ],
                'dismiss' => ['dismissed_at' => now()],
            };

            $reminder->forceFill([
                ...$attributes,
                'revision' => $reminder->revision + 1,
            ])->save();

            ReminderLifecycleEvent::query()->create([
                'reminder_id' => $reminder->id,
                'service_key_id' => $serviceKeyId,
                'schema_version' => $schemaVersion,
                'idempotency_key' => $idempotencyKey,
                'payload_digest' => $payloadDigest,
                'interaction_digest' => $interactionDigest,
                'action' => match ($action) {
                    'acknowledge' => 'acknowledged',
                    'snooze' => 'snoozed',
                    'dismiss' => 'dismissed',
                },
                'reminder_revision' => $reminder->revision,
                'occurred_at' => now(),
                'snoozed_until' => $snoozedUntil,
            ]);

            $this->recordAudit->handle(
                serviceKeyId: $serviceKeyId,
                schemaVersion: $schemaVersion,
                capability: 'reminder.respond',
                outcome: 'success',
                nonceDigest: $nonceDigest,
                requestDigest: $requestDigest,
                interactionDigest: $interactionDigest,
            );

            return ['reminder' => $reminder, 'replayed' => false];
        });
    }
}
