<?php

namespace Database\Factories;

use App\LearnedRuleMatchMode;
use App\LearnedRuleSuggestionStatus;
use App\Models\Category;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearnedRuleSuggestion>
 */
class LearnedRuleSuggestionFactory extends Factory
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
            'category_id' => fn (array $attributes): int => Category::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->id,
            'merchant_pattern' => 'Example Merchant',
            'merchant_key' => 'example merchant',
            'match_mode' => LearnedRuleMatchMode::Exact,
            'definition_hash' => fake()->unique()->sha256(),
            'status' => LearnedRuleSuggestionStatus::Collecting,
            'evidence_count' => 0,
        ];
    }
}
