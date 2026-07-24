<?php

namespace App\Actions\Ledger;

use App\Models\Transaction;
use App\Models\User;

class ReadTransactionForOpenClaw
{
    /**
     * @return array{
     *     id: int,
     *     revision: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     status: string
     * }|null
     */
    public function handle(User $owner, int $transactionId): ?array
    {
        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($transactionId)
            ->first([
                'id',
                'revision',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'merchant_description',
                'voided_at',
            ]);

        if ($transaction === null) {
            return null;
        }

        return [
            'id' => $transaction->id,
            'revision' => $transaction->revision,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'status' => $transaction->voided_at === null ? 'active' : 'voided',
        ];
    }
}
