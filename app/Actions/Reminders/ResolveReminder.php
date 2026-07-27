<?php

namespace App\Actions\Reminders;

use App\Exceptions\IdempotencyKeyConflict;
use App\Models\Reminder;
use App\Models\ReminderLifecycleEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ResolveReminder
{
    public function handle(
        User $owner,
        int $reminderId,
        string $domainAction,
        string $idempotencyKey,
    ): Reminder {
        $domainAction = Str::squish($domainAction);

        if ($domainAction === '' || mb_strlen($domainAction) > 64 || ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('The Reminder resolution is invalid.');
        }

        $payloadDigest = hash('sha256', json_encode([
            'reminder_id' => $reminderId,
            'domain_action' => $domainAction,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $owner,
            $reminderId,
            $domainAction,
            $idempotencyKey,
            $payloadDigest,
        ): Reminder {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $existing = ReminderLifecycleEvent::query()
                ->where('service_key_id', 'money-assistant-domain')
                ->where('schema_version', 1)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_digest, $payloadDigest)) {
                    throw new IdempotencyKeyConflict;
                }

                return Reminder::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->findOrFail($existing->reminder_id);
            }

            $reminder = Reminder::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->find($reminderId);

            if ($reminder === null) {
                throw (new ModelNotFoundException)->setModel(Reminder::class, [$reminderId]);
            }

            if ($reminder->resolved_at === null) {
                $reminder->forceFill([
                    'resolved_at' => now(),
                    'revision' => $reminder->revision + 1,
                ])->save();
            }

            ReminderLifecycleEvent::query()->create([
                'reminder_id' => $reminder->id,
                'service_key_id' => 'money-assistant-domain',
                'schema_version' => 1,
                'idempotency_key' => $idempotencyKey,
                'payload_digest' => $payloadDigest,
                'interaction_digest' => null,
                'action' => 'resolved',
                'domain_action' => $domainAction,
                'reminder_revision' => $reminder->revision,
                'occurred_at' => now(),
                'snoozed_until' => null,
            ]);

            return $reminder;
        });
    }
}
