<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\ExactInteger;
use App\Models\Category;
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
        'original_purchase_id',
        'category_id',
        'category_assignment_provenance',
    ];

    /**
     * @return array{
     *     today: string,
     *     totals: array{USD: string, PEN: string},
     *     category_totals: list<array{
     *         category: array{id: int, name: string},
     *         totals: array{USD: string, PEN: string}
     *     }>,
     *     purchase_options: list<array{
     *         id: int,
     *         occurred_on: string,
     *         merchant_description: string,
     *         currency: string
     *     }>,
     *     transactions: list<array{
     *         id: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         confirmed_at: string,
     *         revision: int,
     *         original_purchase: array{id: int, merchant_description: string}|null,
     *         category: array{id: int, name: string, provenance: string}|null,
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
     *         original_purchase: array{id: int, merchant_description: string}|null,
     *         category: array{id: int, name: string, provenance: string}|null,
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
            ->with([
                'originalPurchase:id,merchant_description',
                'category:id,name',
            ])
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
            ->with([
                'originalPurchase:id,merchant_description',
                'category:id,name',
            ])
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

        $categoryTotalRows = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereNotNull('category_id')
            ->toBase()
            ->select(['category_id', 'currency'])
            ->selectRaw(
                'SUM(CASE WHEN kind = ? THEN amount_minor ELSE -amount_minor END) AS total_minor',
                [TransactionKind::Purchase->value],
            )
            ->groupBy(['category_id', 'currency'])
            ->get();

        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
        $categoryTotalsById = [];

        foreach ($categoryTotalRows as $categoryTotalRow) {
            $categoryTotalsById[(int) $categoryTotalRow->category_id][(string) $categoryTotalRow->currency] = ExactInteger::from(
                (string) $categoryTotalRow->total_minor,
            );
        }

        foreach ($categories as $category) {
            if (
                $category->parent_id === null
                || ! isset($categoryTotalsById[$category->id])
            ) {
                continue;
            }

            foreach (Currency::cases() as $currency) {
                $childTotal = $categoryTotalsById[$category->id][$currency->value]
                    ?? ExactInteger::from(0);
                $parentTotal = $categoryTotalsById[$category->parent_id][$currency->value]
                    ?? ExactInteger::from(0);
                $categoryTotalsById[$category->parent_id][$currency->value] = $parentTotal->add($childTotal);
            }
        }

        $categoryTotals = [];

        foreach ($categories as $category) {
            if (! isset($categoryTotalsById[$category->id])) {
                continue;
            }

            $categoryTotals[] = [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                ],
                'totals' => [
                    Currency::Usd->value => (
                        $categoryTotalsById[$category->id][Currency::Usd->value]
                        ?? ExactInteger::from(0)
                    )->value(),
                    Currency::Pen->value => (
                        $categoryTotalsById[$category->id][Currency::Pen->value]
                        ?? ExactInteger::from(0)
                    )->value(),
                ],
            ];
        }

        $purchaseModels = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->where('kind', TransactionKind::Purchase)
            ->select([
                'id',
                'occurred_on',
                'merchant_description',
                'currency',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
        $purchaseOptions = [];

        foreach ($purchaseModels as $purchase) {
            $purchaseOptions[] = [
                'id' => $purchase->id,
                'occurred_on' => $purchase->occurred_on->toDateString(),
                'merchant_description' => $purchase->merchant_description,
                'currency' => $purchase->currency->value,
            ];
        }

        return [
            'today' => now(config('app.timezone'))->toDateString(),
            'totals' => [
                Currency::Usd->value => (string) $totalRows->get(Currency::Usd->value, '0'),
                Currency::Pen->value => (string) $totalRows->get(Currency::Pen->value, '0'),
            ],
            'category_totals' => $categoryTotals,
            'purchase_options' => $purchaseOptions,
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
     *     revision: int,
     *     original_purchase: array{id: int, merchant_description: string}|null,
     *     category: array{id: int, name: string, provenance: string}|null
     * }
     */
    private function transactionData(Transaction $transaction): array
    {
        $category = null;

        if ($transaction->category !== null) {
            assert($transaction->category_assignment_provenance !== null);
            $category = [
                'id' => $transaction->category->id,
                'name' => $transaction->category->name,
                'provenance' => $transaction->category_assignment_provenance->value,
            ];
        }

        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'revision' => $transaction->revision,
            'original_purchase' => $transaction->originalPurchase === null
                ? null
                : [
                    'id' => $transaction->originalPurchase->id,
                    'merchant_description' => $transaction->originalPurchase->merchant_description,
                ],
            'category' => $category,
        ];
    }
}
