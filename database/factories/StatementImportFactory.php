<?php

namespace Database\Factories;

use App\FinancialStatementFormat;
use App\Models\StatementImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementImport>
 */
class StatementImportFactory extends Factory
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
            'financial_statement_format' => fake()->randomElement(FinancialStatementFormat::cases()),
            'parser_version' => 'fixture-v1',
            'file_hash' => fake()->unique()->sha256(),
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'instrument_label' => 'Synthetic statement',
            'instrument_last_four' => fake()->numerify('####'),
            'reconciliation_values' => [],
            'movement_count' => 0,
            'confirmed_at' => now(),
        ];
    }
}
