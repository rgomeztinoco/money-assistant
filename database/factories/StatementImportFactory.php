<?php

namespace Database\Factories;

use App\Models\StatementImport;
use App\Models\User;
use App\StatementProvider;
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
            'provider' => fake()->randomElement(StatementProvider::cases()),
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
