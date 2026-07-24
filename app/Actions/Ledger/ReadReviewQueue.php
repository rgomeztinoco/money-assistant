<?php

namespace App\Actions\Ledger;

use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

class ReadReviewQueue
{
    /**
     * @return array{
     *     unresolved_field_count: int,
     *     transactions: list<array{
     *         id: int,
     *         revision: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         confirmed_at: string,
     *         fields: list<array{name: string, label: string, value: string}>
     *     }>
     * }
     */
    public function handle(User $owner): array
    {
        $reviewQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereJsonLength('provisional_fields', '>', 0);

        $unresolvedFieldCount = (int) (clone $reviewQuery)
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(provisional_fields)), 0) AS unresolved_field_count')
            ->value('unresolved_field_count');

        $transactionModels = $reviewQuery
            ->select([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'merchant_description',
                'confirmed_at',
                'revision',
                'provisional_fields',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        $transactions = [];

        foreach ($transactionModels as $transaction) {
            $fields = [];

            foreach ($transaction->provisional_fields as $fieldName) {
                $field = ReviewableTransactionField::from($fieldName);
                $fields[] = [
                    'name' => $field->value,
                    'label' => $field->label(),
                    'value' => $field->valueFor($transaction),
                ];
            }

            $transactions[] = [
                'id' => $transaction->id,
                'revision' => $transaction->revision,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'merchant_description' => $transaction->merchant_description,
                'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
                'fields' => $fields,
            ];
        }

        return [
            'unresolved_field_count' => $unresolvedFieldCount,
            'transactions' => $transactions,
        ];
    }
}
