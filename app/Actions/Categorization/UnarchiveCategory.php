<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnarchiveCategory
{
    public function handle(int $categoryId): Category
    {
        try {
            return DB::transaction(function () use ($categoryId): Category {
                $category = Category::query()
                    ->whereKey($categoryId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($category->archived_at === null) {
                    return $category;
                }

                if ($category->parent_id !== null) {
                    $parentIsActive = Category::query()
                        ->whereKey($category->parent_id)
                        ->whereNull('archived_at')
                        ->lockForUpdate()
                        ->exists();

                    if (! $parentIsActive) {
                        throw ValidationException::withMessages([
                            'category' => 'Unarchive or move the parent Category first.',
                        ]);
                    }
                }

                $nameConflictExists = Category::query()
                    ->whereKeyNot($category->id)
                    ->whereNull('archived_at')
                    ->whereRaw('lower(name) = lower(?)', [$category->name])
                    ->when(
                        $category->parent_id === null,
                        fn ($query) => $query->whereNull('parent_id'),
                        fn ($query) => $query->where('parent_id', $category->parent_id),
                    )
                    ->exists();

                if ($nameConflictExists) {
                    throw ValidationException::withMessages([
                        'category' => 'Rename this Category or its active sibling before unarchiving it.',
                    ]);
                }

                $category->archived_at = null;
                $category->save();

                return $category;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'category' => 'Rename this Category or its active sibling before unarchiving it.',
            ]);
        }
    }
}
