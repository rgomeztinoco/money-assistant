<?php

namespace App\Actions\Categorization;

use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\StaleCategoryRevision;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetireCategory
{
    public function __construct(
        private InvalidateAiClassificationValidationContext $invalidateValidationContext,
    ) {}

    public function handle(User $owner, int $categoryId, int $expectedRevision): Category
    {
        return DB::transaction(function () use ($owner, $categoryId, $expectedRevision): Category {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($category->revision !== $expectedRevision) {
                throw new StaleCategoryRevision;
            }

            if ($category->children()->whereNull('retired_at')->exists()) {
                throw new CategoryOperationBlocked('Move or retire every active child Category first.');
            }

            if (LearnedRule::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereNull('retired_at')
                ->whereHas('currentRevision', fn ($query) => $query->where('category_id', $category->id))
                ->exists()) {
                throw new CategoryOperationBlocked('Retire or revise every active Learned Rule targeting this Category first.');
            }

            if ($category->retired_at !== null) {
                return $category;
            }

            $category->retired_at = now()->toImmutable();
            $category->revision++;
            $category->save();
            $this->invalidateValidationContext->handle($owner);

            return $category;
        }, 3);
    }
}
