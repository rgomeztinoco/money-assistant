<?php

namespace Database\Factories;

use App\Models\AiClassificationRequest;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiClassificationRequest>
 */
class AiClassificationRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'user_id' => fn (array $attributes): int => Transaction::query()
                ->whereKey($attributes['transaction_id'])
                ->sole()
                ->user_id,
            'expected_transaction_revision' => fn (array $attributes): int => Transaction::query()
                ->whereKey($attributes['transaction_id'])
                ->sole()
                ->revision,
        ];
    }
}
