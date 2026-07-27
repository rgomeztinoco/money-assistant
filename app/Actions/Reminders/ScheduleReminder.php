<?php

namespace App\Actions\Reminders;

use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ScheduleReminder
{
    public function handle(
        User $owner,
        string $subject,
        DateTimeInterface $scheduledFor,
    ): Reminder {
        $subject = Str::squish($subject);

        if ($subject === '' || mb_strlen($subject) > 255) {
            throw new InvalidArgumentException('A Reminder subject is required.');
        }

        return DB::transaction(fn (): Reminder => Reminder::query()->create([
            'user_id' => $owner->getKey(),
            'subject' => $subject,
            'scheduled_for' => CarbonImmutable::instance($scheduledFor),
        ]));
    }
}
