<?php

namespace Database\Factories;

use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineItem>
 */
class LineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line_item_id' => fake()->uuid(),
            'receipt_breakdown_id' => ReceiptBreakdown::factory(),
            'description' => fake()->words(2, true),
            'role' => 'purchased_item',
            'line_total_minor' => fake()->numberBetween(1, 100_000),
            'requires_review' => false,
        ];
    }
}
