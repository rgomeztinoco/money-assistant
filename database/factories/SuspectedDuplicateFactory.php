<?php

namespace Database\Factories;

use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuspectedDuplicate>
 */
class SuspectedDuplicateFactory extends Factory
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
            'first_transaction_id' => fn (array $attributes): int => Transaction::factory()
                ->create(['user_id' => $attributes['user_id']])
                ->id,
            'second_transaction_id' => fn (array $attributes): int => Transaction::factory()
                ->create(['user_id' => $attributes['user_id']])
                ->id,
        ];
    }
}
