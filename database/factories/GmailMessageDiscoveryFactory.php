<?php

namespace Database\Factories;

use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GmailMessageDiscovery>
 */
class GmailMessageDiscoveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gmail_connection_id' => GmailConnection::factory(),
            'message_id' => (string) Str::uuid(),
            'processed_at' => null,
        ];
    }
}
