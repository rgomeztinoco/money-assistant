<?php

namespace App\Actions\NotificationIngestion;

use App\Actions\Reminders\ResolveReminder;
use App\Actions\Reminders\ScheduleReminder;
use App\Models\ParserProfile;
use App\Models\Reminder;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SynchronizeParserProfileAlerts
{
    public function __construct(
        private ScheduleReminder $scheduleReminder,
        private ResolveReminder $resolveReminder,
    ) {}

    public function handle(User $owner, int $profileId): void
    {
        DB::transaction(function () use ($owner, $profileId): void {
            $profile = ParserProfile::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->findOrFail($profileId);
            $profileName = Str::limit($profile->name, 180, '');

            $this->synchronizeKind(
                owner: $owner,
                profile: $profile,
                outcomes: [SpendingNotificationProcessingOutcome::AuthenticationFailed->value],
                reminderColumn: 'security_alert_reminder_id',
                idempotencyColumn: 'security_alert_resolution_idempotency_key',
                subject: 'Review grouped security failures for '.$profileName,
                domainAction: 'parser_profile.security_alert_resolved',
            );
            $this->synchronizeKind(
                owner: $owner,
                profile: $profile,
                outcomes: [
                    SpendingNotificationProcessingOutcome::Unsupported->value,
                    SpendingNotificationProcessingOutcome::Failed->value,
                ],
                reminderColumn: 'drift_alert_reminder_id',
                idempotencyColumn: 'drift_alert_resolution_idempotency_key',
                subject: 'Review grouped parser drift for '.$profileName,
                domainAction: 'parser_profile.drift_alert_resolved',
            );
        }, 3);
    }

    /** @param list<string> $outcomes */
    private function synchronizeKind(
        User $owner,
        ParserProfile $profile,
        array $outcomes,
        string $reminderColumn,
        string $idempotencyColumn,
        string $subject,
        string $domainAction,
    ): void {
        $hasUnresolvedFailures = $profile->references()
            ->whereIn('processing_outcome', $outcomes)
            ->exists();
        $reminderId = $profile->getAttribute($reminderColumn);
        $reminder = is_int($reminderId)
            ? Reminder::query()->whereBelongsTo($owner, 'owner')->find($reminderId)
            : null;

        if ($hasUnresolvedFailures) {
            if ($reminder !== null && $reminder->resolved_at === null) {
                return;
            }

            $reminder = $this->scheduleReminder->handle(
                owner: $owner,
                subject: $subject,
                scheduledFor: now(),
            );
            $profile->forceFill([
                $reminderColumn => $reminder->id,
                $idempotencyColumn => (string) Str::uuid(),
            ])->save();

            return;
        }

        $idempotencyKey = $profile->getAttribute($idempotencyColumn);

        if ($reminder === null
            || $reminder->resolved_at !== null
            || ! is_string($idempotencyKey)) {
            return;
        }

        $this->resolveReminder->handle(
            owner: $owner,
            reminderId: $reminder->id,
            domainAction: $domainAction,
            idempotencyKey: $idempotencyKey,
        );
    }
}
