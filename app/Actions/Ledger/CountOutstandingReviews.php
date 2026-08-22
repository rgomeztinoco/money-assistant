<?php

namespace App\Actions\Ledger;

use App\Models\LineItem;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CountOutstandingReviews
{
    /**
     * @var array<int, array{categories: int, fields: int, refund_relationships: int}>
     */
    private array $breakdownsByOwner = [];

    public function handle(User $owner): int
    {
        return array_sum($this->breakdown($owner));
    }

    /**
     * @return array{categories: int, fields: int, refund_relationships: int}
     */
    public function breakdown(User $owner): array
    {
        return $this->breakdownsByOwner[$owner->id] ??= $this->readBreakdown($owner);
    }

    /**
     * @return array{categories: int, fields: int, refund_relationships: int}
     */
    private function readBreakdown(User $owner): array
    {
        $reviewableTransactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->select([
                'transactions.category_id',
                'transactions.kind',
                'transactions.provisional_fields',
                'transactions.refund_relationship_review_reasons',
            ])
            ->withExists([
                'receiptBreakdown as receipt_has_line_items' => fn (Builder $query) => $query
                    ->whereHas('lineItems'),
            ]);

        /**
         * @var object{
         *     category_count: int|string,
         *     field_count: int|string,
         *     refund_relationship_count: int|string
         * } $transactionCounts
         */
        $transactionCounts = DB::query()
            ->fromSub($reviewableTransactions, 'reviewable_transactions')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE kind IN (?, ?) AND category_id IS NULL AND NOT receipt_has_line_items) AS category_count',
                [TransactionKind::Spending->value, TransactionKind::Refund->value],
            )
            ->selectRaw('COALESCE(SUM(jsonb_array_length(provisional_fields)), 0) AS field_count')
            ->selectRaw('COALESCE(SUM(jsonb_array_length(refund_relationship_review_reasons)), 0) AS refund_relationship_count')
            ->firstOrFail();

        $lineItemCategoryCount = LineItem::query()
            ->whereNull('category_id')
            ->whereHas('receiptBreakdown', fn ($query) => $query
                ->whereBelongsTo($owner, 'owner')
                ->whereHas('transaction', fn ($query) => $query
                    ->whereNull('voided_at')
                    ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])))
            ->count();

        return [
            'categories' => (int) $transactionCounts->category_count + $lineItemCategoryCount,
            'fields' => (int) $transactionCounts->field_count,
            'refund_relationships' => (int) $transactionCounts->refund_relationship_count,
        ];
    }
}
