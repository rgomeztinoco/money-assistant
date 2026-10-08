<?php

namespace Database\Factories;

use App\DebtEntryKind;
use App\Models\Debt;
use App\Models\DebtEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DebtEntry> */
class DebtEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['debt_id' => Debt::factory(), 'transaction_id' => null, 'kind' => DebtEntryKind::Adjustment,
            'amount_minor' => -100, 'occurred_on' => now()->toDateString(), 'reason' => 'Agreed balance correction'];
    }
}
