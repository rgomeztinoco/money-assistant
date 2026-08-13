<?php

namespace Database\Factories;

use App\Models\ParserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserProfile>
 */
class ParserProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'trusted_sender_address' => 'alerts@bank.example',
            'trusted_sender_domain' => 'bank.example',
            'authentication_mechanism' => 'dmarc',
            'authenticated_domain' => 'bank.example',
            'enabled_at' => now(),
        ];
    }
}
