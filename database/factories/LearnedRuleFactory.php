<?php

namespace Database\Factories;

use App\Models\LearnedRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearnedRule>
 */
class LearnedRuleFactory extends Factory
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
            'revision' => 1,
            'activated_at' => now(),
        ];
    }
}
