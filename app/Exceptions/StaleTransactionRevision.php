<?php

namespace App\Exceptions;

use App\Models\Transaction;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class StaleTransactionRevision extends Exception implements ShouldntReport
{
    private const MESSAGE = 'This Transaction changed while you were reviewing it. Review the current values and try again.';

    /**
     * @param  array{
     *     id: int,
     *     revision: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     provisional_fields: list<string>
     * }  $currentState
     */
    public function __construct(private array $currentState)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function fromTransaction(Transaction $transaction): self
    {
        return new self([
            'id' => $transaction->id,
            'revision' => $transaction->revision,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'provisional_fields' => $transaction->provisional_fields,
        ]);
    }

    /**
     * @return array{
     *     id: int,
     *     revision: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     provisional_fields: list<string>
     * }
     */
    public function currentState(): array
    {
        return $this->currentState;
    }
}
