<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;

class ReadLedger
{
    /**
     * @return array{
     *     today: string,
     *     totals: array{USD: string, PEN: string},
     *     transactions: list<array{
     *         id: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         confirmed_at: string
     *     }>
     * }
     */
    public function handle(User $owner): array
    {
        $totalRows = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->toBase()
            ->select('currency')
            ->selectRaw(
                'SUM(CASE WHEN kind = ? THEN amount_minor ELSE -amount_minor END) AS total_minor',
                [TransactionKind::Purchase->value],
            )
            ->groupBy('currency')
            ->pluck('total_minor', 'currency');

        $transactionModels = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->select([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'merchant_description',
                'confirmed_at',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $transactions = [];

        foreach ($transactionModels as $transaction) {
            $transactions[] = [
                'id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'merchant_description' => $transaction->merchant_description,
                'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            ];
        }

        return [
            'today' => now(config('app.timezone'))->toDateString(),
            'totals' => [
                Currency::Usd->value => (string) $totalRows->get(Currency::Usd->value, '0'),
                Currency::Pen->value => (string) $totalRows->get(Currency::Pen->value, '0'),
            ],
            'transactions' => $transactions,
        ];
    }
}
