<?php

namespace Database\Factories;

use App\CategoryAssignmentProvenance;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryAssignment>
 */
class CategoryAssignmentFactory extends Factory
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
            'category_id' => null,
            'previous_category_id' => null,
            'source' => CategoryAssignmentProvenance::Owner,
            'is_correction' => false,
            'transaction_revision' => 1,
        ];
    }
}
