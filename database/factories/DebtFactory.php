<?php

namespace Database\Factories;

use App\Currency;
use App\DebtDirection;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Debt> */
class DebtFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->words(2, true),
            'counterparty' => fake()->name(), 'direction' => DebtDirection::Owed, 'currency' => Currency::Pen,
            'opening_balance_minor' => 10000, 'opened_on' => now()->toDateString()];
    }
}
