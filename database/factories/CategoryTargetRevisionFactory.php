<?php

namespace Database\Factories;

use App\Models\CategoryTarget;
use App\Models\CategoryTargetRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryTargetRevision>
 */
class CategoryTargetRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_target_id' => CategoryTarget::factory(),
            'revision' => 1,
            'effective_month' => now()->startOfMonth(),
            'amount_minor' => fake()->numberBetween(0, 100_000),
        ];
    }
}
