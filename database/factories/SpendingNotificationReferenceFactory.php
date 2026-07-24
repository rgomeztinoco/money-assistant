<?php

namespace Database\Factories;

use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SpendingNotificationReference>
 */
class SpendingNotificationReferenceFactory extends Factory
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
            'gmail_account_identity' => fake()->unique()->safeEmail(),
            'message_id' => (string) Str::uuid(),
            'processing_outcome' => 'transaction_created',
        ];
    }
}
