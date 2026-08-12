<?php

namespace Database\Factories;

use App\IntegrationFailureKind;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\IntegrationIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationIncident>
 */
class IntegrationIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'integration' => IntegrationService::Gmail,
            'work_type' => IntegrationWorkType::GmailSynchronization,
            'work_id' => fake()->unique()->uuid().':incremental',
            'source_identity' => 'gmail:synchronization:'.fake()->unique()->uuid(),
            'failure_kind' => IntegrationFailureKind::Transient,
            'last_error_code' => 'connection_failed',
            'attempt_count' => 1,
            'first_failed_at' => now()->subMinutes(15),
            'last_failed_at' => now(),
            'visible_at' => now(),
            'retry_until' => now()->addDay(),
            'next_attempt_at' => now()->addMinute(),
        ];
    }
}
