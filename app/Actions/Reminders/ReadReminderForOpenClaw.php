<?php

namespace App\Actions\Reminders;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;

final class ReadReminderForOpenClaw
{
    /**
     * @return array{
     *     reminder: array{id: int, subject: string, scheduled_for: string, revision: int, acknowledged_at: string|null, snoozed_until: string|null, dismissed_at: string|null, resolved_at: string|null},
     *     delivery: array{event_id: string, hook_accepted_at: string|null, channel_delivered_at: string|null}
     * }|null
     */
    public function handle(User $owner, string $eventId): ?array
    {
        $delivery = ReminderDelivery::query()
            ->with('reminder')
            ->admittedOpenClawEvent()
            ->whereKey($eventId)
            ->whereHas('reminder', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->first();

        if ($delivery === null) {
            return null;
        }

        $reminder = $delivery->reminder;

        return [
            'reminder' => $this->state($reminder),
            'delivery' => [
                'event_id' => $delivery->id,
                'hook_accepted_at' => $delivery->accepted_at?->toIso8601String(),
                'channel_delivered_at' => $delivery->delivered_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{id: int, subject: string, scheduled_for: string, revision: int, acknowledged_at: string|null, snoozed_until: string|null, dismissed_at: string|null, resolved_at: string|null}
     */
    public function state(Reminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'subject' => $reminder->subject,
            'scheduled_for' => $reminder->scheduled_for->toIso8601String(),
            'revision' => $reminder->revision,
            'acknowledged_at' => $reminder->acknowledged_at?->toIso8601String(),
            'snoozed_until' => $reminder->snoozed_until?->toIso8601String(),
            'dismissed_at' => $reminder->dismissed_at?->toIso8601String(),
            'resolved_at' => $reminder->resolved_at?->toIso8601String(),
        ];
    }
}
