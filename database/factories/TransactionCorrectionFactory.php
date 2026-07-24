<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionCorrection;
use App\ReviewableTransactionField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionCorrection>
 */
class TransactionCorrectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory()->state([
                'revision' => 2,
            ]),
            'field' => ReviewableTransactionField::MerchantDescription,
            'previous_value' => 'Provisional merchant',
            'corrected_value' => 'Correct merchant',
            'transaction_revision' => 2,
        ];
    }
}
