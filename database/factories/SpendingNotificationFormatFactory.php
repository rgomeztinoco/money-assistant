<?php

namespace Database\Factories;

use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationFormat;
use App\SpendingNotificationFormatPurpose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpendingNotificationFormat>
 */
class SpendingNotificationFormatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parser_profile_version_id' => ParserProfileVersion::factory(),
            'name' => fake()->words(2, true),
            'mime_source' => 'text_plain',
            'purpose' => SpendingNotificationFormatPurpose::Spending,
            'rule_identifier' => hash('sha256', fake()->uuid()),
            'definition' => [
                'subject_marker' => 'Purchase approved',
                'body_marker' => 'Amount:',
                'amount' => [
                    'prefix' => 'Amount: ',
                    'suffix' => "\n",
                    'decimal_separator' => '.',
                    'grouping_separator' => null,
                    'currency_position' => 'before',
                    'currency_mapping' => ['S/' => 'PEN'],
                    'semantics' => 'absolute',
                ],
                'date' => [
                    'prefix' => 'Date: ',
                    'suffix' => "\n",
                    'format' => 'd/m/Y',
                    'timezone' => 'America/Lima',
                ],
                'merchant' => [
                    'prefix' => 'Merchant: ',
                    'suffix' => "\n",
                ],
                'kind' => ['semantics' => 'fixed_purchase'],
            ],
        ];
    }
}
