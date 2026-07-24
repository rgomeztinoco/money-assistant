<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Str;

class ReadLedger
{
    private const TRANSACTION_COLUMNS = [
        'id',
        'occurred_on',
        'amount_minor',
        'currency',
        'kind',
        'merchant_description',
        'confirmed_at',
        'revision',
    ];

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
     *         confirmed_at: string,
     *         revision: int,
     *         state_change_idempotency_key: string
     *     }>,
     *     voided_transactions: list<array{
     *         id: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         confirmed_at: string,
     *         revision: int,
     *         voided_at: string,
     *         state_change_idempotency_key: string
     *     }>
     * }
     */
    public function handle(User $owner): array
    {
        $totalRows = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
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
            ->whereNull('voided_at')
            ->select(self::TRANSACTION_COLUMNS)
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $transactions = [];

        foreach ($transactionModels as $transaction) {
            $transactions[] = [
                ...$this->transactionData($transaction),
                'state_change_idempotency_key' => (string) Str::uuid(),
            ];
        }

        $voidedTransactionModels = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNotNull('voided_at')
            ->select([...self::TRANSACTION_COLUMNS, 'voided_at'])
            ->orderByDesc('voided_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $voidedTransactions = [];

        foreach ($voidedTransactionModels as $transaction) {
            assert($transaction->voided_at !== null);

            $voidedTransactions[] = [
                ...$this->transactionData($transaction),
                'voided_at' => $transaction->voided_at->toIso8601String(),
                'state_change_idempotency_key' => (string) Str::uuid(),
            ];
        }

        return [
            'today' => now(config('app.timezone'))->toDateString(),
            'totals' => [
                Currency::Usd->value => (string) $totalRows->get(Currency::Usd->value, '0'),
                Currency::Pen->value => (string) $totalRows->get(Currency::Pen->value, '0'),
            ],
            'transactions' => $transactions,
            'voided_transactions' => $voidedTransactions,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     confirmed_at: string,
     *     revision: int
     * }
     */
    private function transactionData(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'revision' => $transaction->revision,
        ];
    }
}
