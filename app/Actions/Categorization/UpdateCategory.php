<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateCategory
{
    public function handle(
        int $categoryId,
        string $name,
        ?int $parentId,
    ): Category {
        $name = Str::squish($name);

        try {
            return DB::transaction(function () use ($categoryId, $name, $parentId): Category {
                $category = Category::query()
                    ->whereKey($categoryId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($parentId !== null && $category->children()->exists()) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Move or retire this Category’s children before making it a child.',
                    ]);
                }

                $parent = $this->activeParent($parentId, $category->id);

                if ($category->archived_at === null) {
                    $this->ensureNameAvailable($category, $name, $parent?->id);
                }

                $category->fill([
                    'parent_id' => $parent?->id,
                    'name' => $name,
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

    private function activeParent(?int $parentId, int $categoryId): ?Category
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Category::query()
            ->whereKey($parentId)
            ->whereKeyNot($categoryId)
            ->whereNull('parent_id')
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose an active top-level Category.',
            ]);
        }

        return $parent;
    }

    private function ensureNameAvailable(Category $category, string $name, ?int $parentId): void
    {
        $exists = Category::query()
            ->whereKeyNot($category->id)
            ->whereNull('archived_at')
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
