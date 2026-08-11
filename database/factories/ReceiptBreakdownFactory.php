<?php

namespace Database\Factories;

use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptBreakdown>
 */
class ReceiptBreakdownFactory extends Factory
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
                ->firstOrFail()
                ->user_id,
        ];
    }
}
