<?php

namespace App\Actions\Retention;

use App\Models\Category;
use App\Models\FinancialDataTombstone;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PurgeExpiredFinancialData
{
    public function handle(): int
    {
        $purgedCount = 0;

        Category::query()
            ->expiredTrash()
            ->select('id')
            ->chunkById(100, function ($categories) use (&$purgedCount): void {
                foreach ($categories as $category) {
                    $purgedCount += (int) $this->purgeCategory($category->id);
                }
            });

        ReceiptBreakdown::query()
            ->expiredTrash()
            ->select('id')
            ->chunkById(100, function ($breakdowns) use (&$purgedCount): void {
                foreach ($breakdowns as $breakdown) {
                    $purgedCount += (int) $this->purgeReceiptBreakdown($breakdown->id);
                }
            });

        return $purgedCount;
    }

    private function purgeCategory(int $categoryId): bool
    {
        return DB::transaction(function () use ($categoryId): bool {
            $category = Category::query()
                ->expiredTrash()
                ->whereKey($categoryId)
                ->select(['id', 'user_id', 'deletion_id', 'deleted_at'])
                ->lockForUpdate()
                ->first();

            if ($category === null || $category->deletion_id === null) {
                return false;
            }

            FinancialDataTombstone::query()->create([
                'id' => $category->deletion_id,
                'owner_id' => $category->user_id,
                'resource_type' => 'category',
                'resource_id' => $category->id,
                'deleted_at' => $category->deleted_at,
                'purged_at' => now(),
            ]);

            $category->forceDelete();

            return true;
        }, 3);
    }

    private function purgeReceiptBreakdown(int $breakdownId): bool
    {
        $transactionId = ReceiptBreakdown::query()
            ->expiredTrash()
            ->whereKey($breakdownId)
            ->value('transaction_id');

        if ($transactionId === null) {
            return false;
        }

        return DB::transaction(function () use ($breakdownId, $transactionId): bool {
            Transaction::query()
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();
            $breakdown = ReceiptBreakdown::query()
                ->expiredTrash()
                ->whereKey($breakdownId)
                ->select([
                    'id',
                    'user_id',
                    'transaction_id',
                    'receipt_proposal_id',
                    'deletion_id',
                    'deleted_at',
                ])
                ->lockForUpdate()
                ->first();

            if ($breakdown === null || $breakdown->deletion_id === null) {
                return false;
            }

            FinancialDataTombstone::query()->create([
                'id' => $breakdown->deletion_id,
                'owner_id' => $breakdown->user_id,
                'resource_type' => 'receipt_breakdown',
                'resource_id' => $breakdown->id,
                'source_reference_type' => $breakdown->receipt_proposal_id === null
                    ? null
                    : 'receipt_proposal',
                'source_reference_id' => $breakdown->receipt_proposal_id,
                'deleted_at' => $breakdown->deleted_at,
                'purged_at' => now(),
            ]);

            $breakdown->forceDelete();

            return true;
        }, 3);
    }
}
