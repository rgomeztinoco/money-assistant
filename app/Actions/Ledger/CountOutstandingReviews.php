<?php

namespace App\Actions\Ledger;

use App\Models\LineItem;
use App\Models\Transaction;

class CountOutstandingReviews
{
    public function handle(): int
    {
        $categoryCount = Transaction::query()
            ->whereNull('voided_at')
            ->whereCategoryRequiresReview()
            ->count();
        $lineItemCategoryCount = LineItem::query()
            ->whereNull('category_id')
            ->whereHas('receiptBreakdown', fn ($query) => $query
                ->whereHas('transaction', fn ($query) => $query->whereNull('voided_at')))
            ->count();
        $fieldCount = (int) Transaction::query()
            ->whereNull('voided_at')
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(provisional_fields)), 0) AS outstanding_count')
            ->value('outstanding_count');
        $refundRelationshipCount = (int) Transaction::query()
            ->whereNull('voided_at')
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(refund_relationship_review_reasons)), 0) AS outstanding_count')
            ->value('outstanding_count');

        return $categoryCount + $lineItemCategoryCount + $fieldCount + $refundRelationshipCount;
    }
}
