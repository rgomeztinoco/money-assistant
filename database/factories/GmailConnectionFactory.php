<?php

namespace Database\Factories;

use App\Models\GmailConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GmailConnection>
 */
class GmailConnectionFactory extends Factory
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
            'gmail_account_identity' => fake()->unique()->safeEmail(),
            'access_token' => 'access-token-'.fake()->uuid(),
            'refresh_token' => 'refresh-token-'.fake()->uuid(),
            'access_token_expires_at' => now()->addHour(),
            'granted_scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
            'connected_at' => now()->subDay(),
            'initial_sync_starts_at' => now()->subDays(GmailConnection::DEFAULT_IMPORT_LOOKBACK_DAYS),
            'last_successful_check_at' => now()->subMinute(),
            'last_check_failed_at' => null,
            'reauthorization_required_at' => null,
            'last_error_code' => null,
            'history_id' => (string) fake()->numberBetween(100_000, 999_999),
            'initial_sync_completed_at' => now()->subDay(),
            'last_successful_sync_at' => now()->subMinute(),
            'last_synchronization_failed_at' => null,
            'last_synchronization_error_code' => null,
        ];
    }

    public function reauthorizationRequired(): static
    {
        return $this->state(fn (): array => [
            'reauthorization_required_at' => now(),
            'last_error_code' => GmailConnection::ERROR_REFRESH_TOKEN_REJECTED,
        ]);
    }
}
