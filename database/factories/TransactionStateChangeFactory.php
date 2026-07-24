<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionStateChange;
use App\TransactionVoidOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionStateChange>
 */
class TransactionStateChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory()->state([
                'revision' => 2,
                'voided_at' => now(),
            ]),
            'user_id' => fn (array $attributes): int => Transaction::query()
                ->whereKey($attributes['transaction_id'])
                ->firstOrFail()
                ->user_id,
            'idempotency_key' => fake()->uuid(),
            'operation' => TransactionVoidOperation::Void,
            'expected_revision' => 1,
            'result_revision' => 2,
            'result_voided_at' => now(),
        ];
    }
}
