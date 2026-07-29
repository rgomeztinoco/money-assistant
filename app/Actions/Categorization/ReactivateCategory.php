<?php

namespace App\Actions\Categorization;

use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\StaleCategoryRevision;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReactivateCategory
{
    public function __construct(
        private InvalidateAiClassificationValidationContext $invalidateValidationContext,
    ) {}

    public function handle(
        User $owner,
        int $categoryId,
        int $expectedRevision,
        ?int $expectedParentRevision = null,
    ): Category {
        try {
            return DB::transaction(function () use ($owner, $categoryId, $expectedRevision, $expectedParentRevision): Category {
                $category = Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($categoryId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($category->revision !== $expectedRevision) {
                    throw new StaleCategoryRevision;
                }

                if ($category->retired_at === null) {
                    return $category;
                }

                if ($category->parent_id !== null) {
                    $activeParent = Category::query()
                        ->whereBelongsTo($owner, 'owner')
                        ->whereKey($category->parent_id)
                        ->whereNull('retired_at')
                        ->lockForUpdate()
                        ->first();

                    if ($activeParent === null) {
                        throw new CategoryOperationBlocked('Reactivate or move the parent Category first.');
                    }

                    if ($expectedParentRevision !== null
                        && $activeParent->revision !== $expectedParentRevision) {
                        throw new StaleCategoryRevision;
                    }
                }

                $nameConflict = Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKeyNot($category->id)
                    ->whereNull('retired_at')
                    ->whereRaw('lower(name) = lower(?)', [$category->name])
                    ->when(
                        $category->parent_id === null,
                        fn ($query) => $query->whereNull('parent_id'),
                        fn ($query) => $query->where('parent_id', $category->parent_id),
                    )
                    ->exists();

                if ($nameConflict) {
                    throw new CategoryOperationBlocked('Rename this Category or the active sibling with the same name first.');
                }

                $category->retired_at = null;
                $category->revision++;
                $category->save();
                $this->invalidateValidationContext->handle($owner);

                return $category;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw new CategoryOperationBlocked('Rename this Category or the active sibling with the same name first.');
        }
    }
}
