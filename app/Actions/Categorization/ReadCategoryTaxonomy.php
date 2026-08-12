<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use App\Models\User;

final class ReadCategoryTaxonomy
{
    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     parent_id: int|null,
     *     archived_at: string|null,
     *     transaction_count: int,
     *     children: list<array{id: int, parent_id: int|null, name: string, archived_at: string|null, transaction_count: int}>
     * }>
     */
    public function handle(User $owner): array
    {
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->select([
                'id',
                'user_id',
                'parent_id',
                'name',
                'archived_at',
            ])
            ->withCount('transactions')
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderByRaw('lower(name)')
            ->get();

        return array_values($categories
            ->whereNull('parent_id')
            ->map(fn (Category $category): array => [
                ...$this->categoryData($category),
                'children' => array_values($categories
                    ->where('parent_id', $category->id)
                    ->map(fn (Category $child): array => $this->categoryData($child))
                    ->values()
                    ->all()),
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array{id: int, path: string}>
     */
    public function activeOptions(User $owner): array
    {
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('archived_at')
            ->where(fn ($query) => $query
                ->whereNull('parent_id')
                ->orWhereHas('parent', fn ($query) => $query->whereNull('archived_at')))
            ->select(['id', 'user_id', 'parent_id', 'name'])
            ->with('parent:id,name')
            ->orderByRaw('lower(name)')
            ->get();

        return array_values($categories
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'path' => $category->parent === null
                    ? $category->name
                    : $category->parent->name.' > '.$category->name,
            ])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /**
     * @return array{id: int, parent_id: int|null, name: string, archived_at: string|null, transaction_count: int}
     */
    private function categoryData(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'archived_at' => $category->archived_at?->toIso8601String(),
            'transaction_count' => $category->transactions_count,
        ];
    }
}
