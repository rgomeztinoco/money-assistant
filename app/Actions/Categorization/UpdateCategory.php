<?php

namespace App\Actions\Categorization;

use App\Exceptions\StaleCategoryRevision;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateCategory
{
    public function handle(
        User $owner,
        int $categoryId,
        int $expectedRevision,
        string $name,
        ?int $parentId,
        ?int $expectedParentRevision = null,
    ): Category {
        $name = Str::squish($name);

        try {
            return DB::transaction(function () use ($owner, $categoryId, $expectedRevision, $name, $parentId, $expectedParentRevision): Category {
                $category = Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($categoryId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($category->revision !== $expectedRevision) {
                    throw new StaleCategoryRevision;
                }

                if ($parentId !== null && $category->children()->exists()) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Move or retire this Category’s children before making it a child.',
                    ]);
                }

                $parent = $this->activeParent($owner, $parentId, $category->id);

                if ($parent !== null
                    && $expectedParentRevision !== null
                    && $parent->revision !== $expectedParentRevision) {
                    throw new StaleCategoryRevision;
                }

                if ($category->retired_at === null) {
                    $this->ensureNameAvailable($owner, $category, $name, $parent?->id);
                }

                $category->fill([
                    'parent_id' => $parent?->id,
                    'name' => $name,
                    'revision' => $category->revision + 1,
                ])->save();

                return $category;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'name' => 'An active sibling Category already uses this name.',
            ]);
        }
    }

    private function activeParent(User $owner, ?int $parentId, int $categoryId): ?Category
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($parentId)
            ->whereKeyNot($categoryId)
            ->whereNull('parent_id')
            ->whereNull('retired_at')
            ->lockForUpdate()
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose an active top-level Category owned by you.',
            ]);
        }

        return $parent;
    }

    private function ensureNameAvailable(User $owner, Category $category, string $name, ?int $parentId): void
    {
        $exists = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKeyNot($category->id)
            ->whereNull('retired_at')
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'An active sibling Category already uses this name.',
            ]);
        }
    }
}
