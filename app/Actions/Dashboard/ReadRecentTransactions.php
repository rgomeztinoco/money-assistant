<?php

namespace App\Actions\Dashboard;

use App\Models\Transaction;
use App\Models\User;

final class ReadRecentTransactions
{
    /**
     * @return list<array{
     *     id: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     direction: string,
     *     transfer_purpose: string|null,
     *     description: string
     * }>
     */
    public function handle(User $owner): array
    {
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->select([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'direction',
                'transfer_purpose',
                'description',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'direction' => $transaction->direction->value,
                'transfer_purpose' => $transaction->transfer_purpose?->value,
                'description' => $transaction->description,
            ])
            ->all();

        return array_values($transactions);
    }
}
