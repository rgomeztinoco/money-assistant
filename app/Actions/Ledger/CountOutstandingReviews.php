<?php

namespace App\Actions\Ledger;

use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;

class CountOutstandingReviews
{
    public function handle(User $owner): int
    {
        $categoryCount = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereNull('category_id')
            ->count();
        $fieldCount = (int) Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(provisional_fields)), 0) AS outstanding_count')
            ->value('outstanding_count');
        $refundRelationshipCount = (int) Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(refund_relationship_review_reasons)), 0) AS outstanding_count')
            ->value('outstanding_count');
        $suspectedDuplicateCount = SuspectedDuplicate::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('resolved_at')
            ->count();

        return $categoryCount + $fieldCount + $refundRelationshipCount + $suspectedDuplicateCount;
    }
}
