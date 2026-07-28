<?php

namespace Database\Factories;

use App\Models\ReceiptProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptProposal>
 */
class ReceiptProposalFactory extends Factory
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
            'proposal_id' => fake()->uuid(),
            'source_kind' => 'receipt_photo',
            'processed_at' => now(),
            'provider' => 'openai',
            'model' => 'openai/gpt-5.6',
            'contract_version' => 1,
            'proposed_transaction' => [
                'occurred_on' => now()->toDateString(),
                'amount_minor' => 2590,
                'currency' => 'PEN',
                'kind' => 'purchase',
                'merchant_description' => fake()->company(),
            ],
            'proposed_line_items' => [[
                'description' => fake()->words(2, true),
                'line_total_minor' => 2590,
            ]],
        ];
    }
}
