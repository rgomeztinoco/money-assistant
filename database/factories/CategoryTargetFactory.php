<?php

namespace Database\Factories;

use App\Currency;
use App\Models\Category;
use App\Models\CategoryTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryTarget>
 */
class CategoryTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'user_id' => fn (array $attributes): int => (int) Category::query()
                ->whereKey($attributes['category_id'])
                ->soleValue('user_id'),
            'currency' => Currency::Pen,
            'starts_on' => now()->startOfMonth(),
            'revision' => 1,
        ];
    }
}
