<?php

namespace Database\Factories;

use App\CategoryAssignmentProvenance;
use App\Models\AiCategoryProposal;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCategoryProposal>
 */
class AiCategoryProposalFactory extends Factory
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
            'category_assignment_id' => fn (array $attributes): int => CategoryAssignment::factory()->create([
                'transaction_id' => $attributes['transaction_id'],
                'source' => CategoryAssignmentProvenance::Ai,
                'ai_classifier_version' => 'classifier-2026-07',
                'ai_confidence' => 90,
                'ai_outcome' => 'missing_category',
                'ai_explanation' => 'No active Category fits.',
                'ai_requires_review' => true,
            ])->id,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'examples' => [fake()->words(2, true)],
            'revision' => 1,
        ];
    }
}
