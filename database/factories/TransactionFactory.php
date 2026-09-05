<?php

namespace Database\Factories;

use App\Currency;
use App\IncomeSource;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\ReviewableTransactionField;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'occurred_on' => fake()->dateTimeBetween('-1 year', 'now'),
            'amount_minor' => fake()->numberBetween(1, 100_000),
            'currency' => fake()->randomElement(Currency::cases()),
            'kind' => TransactionKind::Spending,
            'direction' => MovementDirection::Debit,
            'description' => fake()->company(),
            'confirmed_at' => now(),
        ];
    }

    public function spending(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Spending,
            'direction' => MovementDirection::Debit,
        ]);
    }

    public function refund(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Refund,
            'direction' => MovementDirection::Credit,
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Income,
            'direction' => MovementDirection::Credit,
            'income_source' => IncomeSource::Other,
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => TransactionKind::Transfer,
            'direction' => MovementDirection::Debit,
            'transfer_purpose' => TransferPurpose::Internal,
        ]);
    }

    public function usd(): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => Currency::Usd,
        ]);
    }

    public function pen(): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => Currency::Pen,
        ]);
    }

    /**
     * @param  list<ReviewableTransactionField>  $fields
     */
    public function provisional(array $fields): static
    {
        return $this->state(fn (array $attributes) => [
            'provisional_fields' => collect($fields)
                ->map(fn (ReviewableTransactionField $field): string => $field->value)
                ->all(),
        ]);
    }
}
