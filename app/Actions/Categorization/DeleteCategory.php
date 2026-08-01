<?php

namespace App\Actions\Categorization;

use App\Exceptions\StaleCategoryRevision;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCategory
{
    public function __construct(
        private InvalidateAiClassificationValidationContext $invalidateValidationContext,
        private EnsureCategoryCanBeDeleted $ensureCategoryCanBeDeleted,
    ) {}

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

            $this->ensureCategoryCanBeDeleted->handle($category);

            $category->moveToFinancialTrash();
            $this->invalidateValidationContext->handle($owner);
        }, 3);
    }
}
