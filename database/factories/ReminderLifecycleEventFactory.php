<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderLifecycleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderLifecycleEvent>
 */
class ReminderLifecycleEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reminder_id' => Reminder::factory(),
            'service_key_id' => 'openclaw-service-2026-07',
            'schema_version' => 1,
            'idempotency_key' => fake()->uuid(),
            'payload_digest' => fake()->sha256(),
            'interaction_digest' => fake()->sha256(),
            'action' => 'acknowledged',
            'reminder_revision' => 2,
            'occurred_at' => now(),
        ];
    }
}
