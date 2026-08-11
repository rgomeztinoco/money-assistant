<?php

namespace App\Actions\Retention;

use App\Models\Category;
use App\Models\FinancialDataTombstone;
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
}
