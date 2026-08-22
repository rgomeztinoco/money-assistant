<?php

namespace Database\Factories;

use App\Currency;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\MovementDirection;
use App\StatementMovementClassification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementMovement>
 */
class StatementMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement_import_id' => StatementImport::factory(),
            'transaction_id' => Transaction::factory()->spending(),
            'source_row_id' => fake()->unique()->sha256(),
            'position' => fake()->unique()->numberBetween(1, 1000),
            'occurred_on' => fake()->dateTimeBetween('2026-02-01', '2026-02-28'),
            'amount_minor' => fake()->numberBetween(1, 100_000),
            'currency' => fake()->randomElement(Currency::cases()),
            'direction' => MovementDirection::Debit,
            'classification' => StatementMovementClassification::Purchase,
            'description' => fake()->sentence(3),
            'source_metadata' => [],
        ];
    }
}
