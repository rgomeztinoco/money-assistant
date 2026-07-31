<?php

namespace Database\Factories;

use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserProfileVersion>
 */
class ParserProfileVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parser_profile_id' => ParserProfile::factory(),
            'version' => 1,
            'trusted_sender_address' => 'alerts@bank.example',
            'trusted_sender_domain' => 'bank.example',
            'authentication_mechanism' => 'dmarc',
            'authenticated_domain' => 'bank.example',
            'source_gmail_account_identity' => fake()->safeEmail(),
            'source_message_id' => fake()->uuid(),
            'approved_at' => now(),
        ];
    }
}
