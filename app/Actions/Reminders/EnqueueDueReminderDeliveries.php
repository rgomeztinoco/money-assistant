<?php

namespace App\Actions\Reminders;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnqueueDueReminderDeliveries
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            Reminder::query()
                ->where('scheduled_for', '<=', now())
                ->whereNull('dismissed_at')
                ->whereNull('resolved_at')
                ->whereDoesntHave(
                    'deliveries',
                    fn ($query) => $query->whereColumn(
                        'reminder_deliveries.scheduled_for',
                        'reminders.scheduled_for',
                    ),
                )
                ->orderBy('scheduled_for')
                ->lockForUpdate()
                ->limit(100)
                ->get()
                ->each(fn (Reminder $reminder) => ReminderDelivery::query()->create([
                    'id' => (string) Str::uuid(),
                    'reminder_id' => $reminder->getKey(),
                    'event_type' => 'reminder.due',
                    'scheduled_for' => $reminder->scheduled_for,
                    'occurred_at' => now(),
                    'next_attempt_at' => now(),
                ]));
        });
    }
}
