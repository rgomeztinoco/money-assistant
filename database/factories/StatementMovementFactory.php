<?php

namespace Database\Factories;

use App\Currency;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\StatementMovementClassification;
use App\StatementMovementDirection;
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
            'transaction_id' => null,
            'source_row_id' => fake()->unique()->sha256(),
            'position' => fake()->unique()->numberBetween(1, 1000),
            'occurred_on' => fake()->dateTimeBetween('2026-02-01', '2026-02-28'),
            'amount_minor' => fake()->numberBetween(1, 100_000),
            'currency' => fake()->randomElement(Currency::cases()),
            'direction' => fake()->randomElement(StatementMovementDirection::cases()),
            'classification' => StatementMovementClassification::AlreadyRecorded,
            'description' => fake()->sentence(3),
            'instrument_label' => 'Synthetic statement',
            'instrument_last_four' => null,
            'source_metadata' => [],
        ];
    }
}
