<?php

namespace App\Actions\Retention;

use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;

class ReadFinancialTrash
{
    /** @return list<array{deletion_id: string, name: string, purge_after: string}> */
    public function categories(User $owner): array
    {
        return array_values(Category::query()
            ->restorableTrash()
            ->whereBelongsTo($owner, 'owner')
            ->select(['id', 'user_id', 'deletion_id', 'name', 'purge_after'])
            ->orderBy('purge_after')
            ->get()
            ->map($this->categoryData(...))
            ->values()
            ->all());
    }

    /** @return list<array{deletion_id: string, revision: int, purge_after: string}> */
    public function receiptBreakdowns(User $owner, Transaction $transaction): array
    {
        return array_values(ReceiptBreakdown::query()
            ->restorableTrash()
            ->whereBelongsTo($owner, 'owner')
            ->whereBelongsTo($transaction)
            ->select([
                'id',
                'user_id',
                'transaction_id',
                'deletion_id',
                'revision',
                'purge_after',
            ])
            ->orderBy('purge_after')
            ->get()
            ->map($this->receiptBreakdownData(...))
            ->values()
            ->all());
    }

    /** @return array{deletion_id: string, name: string, purge_after: string} */
    private function categoryData(Category $category): array
    {
        if ($category->deletion_id === null || $category->purge_after === null) {
            throw new \LogicException('Restorable Category trash metadata is incomplete.');
        }

        return [
            'deletion_id' => $category->deletion_id,
            'name' => $category->name,
            'purge_after' => $category->purge_after->toIso8601String(),
        ];
    }

    /** @return array{deletion_id: string, revision: int, purge_after: string} */
    private function receiptBreakdownData(ReceiptBreakdown $breakdown): array
    {
        if ($breakdown->deletion_id === null || $breakdown->purge_after === null) {
            throw new \LogicException('Restorable Receipt Breakdown trash metadata is incomplete.');
        }

        return [
            'deletion_id' => $breakdown->deletion_id,
            'revision' => $breakdown->revision,
            'purge_after' => $breakdown->purge_after->toIso8601String(),
        ];
    }
}
