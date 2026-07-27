<?php

namespace Database\Factories;

use App\Currency;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DailyExchangeRateSeedRequest>
 */
class DailyExchangeRateSeedRequestFactory extends Factory
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
            'resolution_idempotency_key' => Str::uuid(),
        ];
    }

    public function required(): static
    {
        return $this->afterCreating(function (DailyExchangeRateSeedRequest $seedRequest): void {
            $seedRequest->owner->forceFill(['reporting_currency' => Currency::Pen])->save();
            Transaction::factory()->for($seedRequest->owner, 'owner')->create([
                'occurred_on' => $seedRequest->applicable_on,
                'currency' => Currency::Usd,
            ]);
        });
    }
}
