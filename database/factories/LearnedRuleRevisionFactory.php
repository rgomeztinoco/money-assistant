<?php

namespace Database\Factories;

use App\LearnedRuleMatchMode;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearnedRuleRevision>
 */
class LearnedRuleRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learned_rule_id' => LearnedRule::factory(),
            'revision' => 1,
            'category_id' => fn (array $attributes): int => Category::factory()->create([
                'user_id' => LearnedRule::query()
                    ->whereKey($attributes['learned_rule_id'])
                    ->sole()
                    ->user_id,
            ])->id,
            'merchant_pattern' => 'Example Merchant',
            'merchant_key' => 'example merchant',
            'match_mode' => LearnedRuleMatchMode::Exact,
        ];
    }
}
