<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ReadCategoryTaxonomy
{
    /**
     * @param  array{search?: string, archived?: 'without'|'with'|'only', sort?: 'name'|'children'|'transactions'|'rules', direction?: 'asc'|'desc'}  $filters
     * @return list<array{
     *     id: int,
     *     name: string,
     *     parent_id: int|null,
     *     archived_at: string|null,
     *     child_count: int,
     *     transaction_count: int,
     *     active_merchant_rule_count: int,
     *     archive_impact: array{active_child_count: int, active_merchant_rule_count: int},
     *     children: list<array{id: int, parent_id: int|null, name: string, archived_at: string|null, child_count: int, transaction_count: int, active_merchant_rule_count: int, archive_impact: array{active_child_count: int, active_merchant_rule_count: int}}>
     * }>
     */
    public function handle(User $owner, array $filters = []): array
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
            ->withCount([
                'children',
                'children as active_child_count' => fn ($query) => $query->whereNull('archived_at'),
                'transactions',
                'merchantRules as active_merchant_rule_count' => fn ($query) => $query->where('enabled', true),
            ])
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderByRaw('lower(name)')
            ->get();

        $archived = $filters['archived'] ?? 'with';
        $search = $this->searchable($filters['search'] ?? '');
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        return array_values($this->sortCategories(
            $categories
                ->whereNull('parent_id')
                ->filter(function (Category $category) use ($categories, $archived): bool {
                    if ($archived === 'with') {
                        return true;
                    }

                    if ($archived === 'without') {
                        return $category->archived_at === null;
                    }

                    return $category->archived_at !== null
                        || $categories->where('parent_id', $category->id)->contains(
                            fn (Category $child): bool => $child->archived_at !== null,
                        );
                }),
            $sort,
            $direction,
        )
            ->map(function (Category $category) use ($categories, $archived, $search, $sort, $direction): ?array {
                $children = $this->sortCategories(
                    $categories
                        ->where('parent_id', $category->id)
                        ->filter(fn (Category $child): bool => match ($archived) {
                            'without' => $child->archived_at === null,
                            'only' => $child->archived_at !== null,
                            default => true,
                        }),
                    $sort,
                    $direction,
                );
                $rootMatches = $search === '' || str_contains($this->searchable($category->name), $search);

                if (! $rootMatches) {
                    $children = $children->filter(
                        fn (Category $child): bool => str_contains($this->searchable($child->name), $search),
                    );
                }

                if (! $rootMatches && $children->isEmpty()) {
                    return null;
                }

                return [
                    ...$this->categoryData($category, $categories),
                    'children' => array_values($children
                        ->map(fn (Category $child): array => $this->categoryData($child, $categories))
                        ->values()
                        ->all()),
                ];
            })
            ->filter()
            ->values()
            ->all());
    }

    /**
     * @return list<array{id: int, name: string, path: string, parent_id: int|null, parent_name: string|null}>
     */
    public function activeOptions(User $owner): array
    {
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->availableForAssignment()
            ->select(['id', 'user_id', 'parent_id', 'name'])
            ->with('parent:id,name')
            ->orderByRaw('lower(name)')
            ->get();

        return array_values($categories
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->parent === null
                    ? $category->name
                    : $category->parent->name.' > '.$category->name,
                'parent_id' => $category->parent_id,
                'parent_name' => $category->parent?->name,
            ])
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array{id: int, parent_id: int|null, name: string, archived_at: string|null, child_count: int, transaction_count: int, active_merchant_rule_count: int, archive_impact: array{active_child_count: int, active_merchant_rule_count: int}}
     */
    private function categoryData(Category $category, Collection $categories): array
    {
        $affectedCategories = $category->parent_id === null
            ? $categories->filter(fn (Category $candidate): bool => $candidate->id === $category->id
                || ($candidate->parent_id === $category->id && $candidate->archived_at === null))
            : collect([$category]);

        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'archived_at' => $category->archived_at?->toIso8601String(),
            'child_count' => $category->children_count,
            'transaction_count' => $category->transactions_count,
            'active_merchant_rule_count' => $category->active_merchant_rule_count,
            'archive_impact' => [
                'active_child_count' => $category->active_child_count,
                'active_merchant_rule_count' => $affectedCategories->sum('active_merchant_rule_count'),
            ],
        ];
    }

    private function searchable(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  'name'|'children'|'transactions'|'rules'  $sort
     * @param  'asc'|'desc'  $direction
     * @return Collection<int, Category>
     */
    private function sortCategories(Collection $categories, string $sort, string $direction): Collection
    {
        return $categories->sort(function (Category $left, Category $right) use ($sort, $direction): int {
            $leftValue = $this->sortValue($left, $sort);
            $rightValue = $this->sortValue($right, $sort);
            $comparison = is_int($leftValue)
                ? $leftValue <=> $rightValue
                : strnatcasecmp($leftValue, (string) $rightValue);

            if ($comparison === 0) {
                $comparison = strnatcasecmp($left->name, $right->name);
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });
    }

    /** @param  'name'|'children'|'transactions'|'rules'  $sort */
    private function sortValue(Category $category, string $sort): int|string
    {
        return match ($sort) {
            'children' => (int) $category->children_count,
            'transactions' => (int) $category->transactions_count,
            'rules' => $category->active_merchant_rule_count,
            default => $category->name,
        };
    }
}
