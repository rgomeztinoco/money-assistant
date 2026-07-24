<?php

namespace Database\Factories;

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'occurred_on' => fake()->dateTimeBetween('-1 year', 'now'),
            'amount_minor' => fake()->numberBetween(1, 100_000),
            'currency' => fake()->randomElement(Currency::cases()),
            'kind' => fake()->randomElement(TransactionKind::cases()),
            'merchant_description' => fake()->company(),
            'confirmed_at' => now(),
        ];
    }

    public function purchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Purchase,
        ]);
    }

    public function refund(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Refund,
        ]);
    }

    public function usd(): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => Currency::Usd,
        ]);
    }

    public function pen(): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => Currency::Pen,
        ]);
    }
}
