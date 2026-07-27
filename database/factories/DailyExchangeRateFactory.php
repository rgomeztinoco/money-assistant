<?php

namespace Database\Factories;

use App\Models\DailyExchangeRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyExchangeRate>
 */
class DailyExchangeRateFactory extends Factory
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
            'applicable_on' => fake()->unique()->dateTimeBetween('-1 year', 'now'),
            'pen_per_usd_scaled' => fake()->numberBetween(3_000_000, 4_500_000),
            'owner_managed_at' => now(),
        ];
    }
}
