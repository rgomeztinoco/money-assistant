<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveCategory
{
    public function handle(User $owner, int $categoryId): Category
    {
        return DB::transaction(function () use ($owner, $categoryId): Category {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($category->archived_at !== null) {
                return $category;
            }

            $categoryIds = [$category->id];

            if ($category->parent_id === null) {
                $categoryIds = [
                    ...$categoryIds,
                    ...$category->children()
                        ->whereNull('archived_at')
                        ->lockForUpdate()
                        ->pluck('id')
                        ->all(),
                ];
            }

            $archivedAt = now()->toImmutable();

            Category::query()
                ->whereIn('id', $categoryIds)
                ->update(['archived_at' => $archivedAt]);

            MerchantRule::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereIn('category_id', $categoryIds)
                ->where('enabled', true)
                ->update(['enabled' => false]);

            return $category->refresh();
        }, 3);
    }
}
