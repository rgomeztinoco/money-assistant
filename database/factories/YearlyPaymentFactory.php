<?php

namespace Database\Factories;

use App\Currency;
use App\Models\User;
use App\Models\YearlyPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<YearlyPayment> */
class YearlyPaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'amount_minor' => 240000,
            'currency' => Currency::Pen,
            'cushion_minor' => 0,
            'expected_due_on' => null,
            'is_active' => true,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
