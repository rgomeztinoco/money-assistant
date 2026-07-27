<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReminderDelivery>
 */
class ReminderDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'reminder_id' => Reminder::factory(),
            'event_type' => 'reminder.due',
            'scheduled_for' => now(),
            'occurred_at' => now(),
            'next_attempt_at' => now(),
        ];
    }
}
