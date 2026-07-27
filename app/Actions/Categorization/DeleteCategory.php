<?php

namespace App\Actions\Categorization;

use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\StaleCategoryRevision;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCategory
{
    public function handle(User $owner, int $categoryId, int $expectedRevision): void
    {
        DB::transaction(function () use ($owner, $categoryId, $expectedRevision): void {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($category->revision !== $expectedRevision) {
                throw new StaleCategoryRevision;
            }

            if ($category->transactions()->exists() || $category->assignments()->exists()) {
                throw new CategoryOperationBlocked('This Category has historical Transaction assignments and must be retired instead.');
            }

            if ($category->children()->exists()) {
                throw new CategoryOperationBlocked('Move or delete every child Category first.');
            }

            $category->delete();
        }, 3);
    }
}
