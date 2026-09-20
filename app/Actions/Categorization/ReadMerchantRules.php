<?php

namespace App\Actions\Categorization;

use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Support\Str;

final class ReadMerchantRules
{
    /**
     * @param  array{search?: string, category_id?: int|null, status?: 'all'|'enabled'|'disabled', kind?: 'all'|'any'|'spending'|'refund', currency?: 'all'|'any'|'PEN'|'USD', sort?: 'category'|'merchant'|'kind'|'currency'|'status', direction?: 'asc'|'desc'}  $filters
     * @return list<array{id: int, category_id: int, category_name: string, category_path: string, merchant: string, merchant_key: string, transaction_kind: string|null, currency: string|null, enabled: bool}>
     */
    public function handle(User $owner, array $filters = []): array
    {
        $search = $this->searchable($filters['search'] ?? '');
        $categoryId = $filters['category_id'] ?? null;
        $status = $filters['status'] ?? 'all';
        $kind = $filters['kind'] ?? 'all';
        $currency = $filters['currency'] ?? 'all';
        $sort = $filters['sort'] ?? 'category';
        $direction = $filters['direction'] ?? 'asc';

        return array_values(MerchantRule::query()
            ->whereBelongsTo($owner, 'owner')
            ->select([
                'id',
                'category_id',
                'merchant',
                'merchant_key',
                'transaction_kind',
                'currency',
                'enabled',
            ])
            ->with('category:id,parent_id,name', 'category.parent:id,name')
            ->get()
            ->map(fn (MerchantRule $rule): array => [
                'id' => $rule->id,
                'category_id' => $rule->category_id,
                'category_name' => $rule->category->name,
                'category_path' => $this->categoryPath($rule),
                'merchant' => $rule->merchant,
                'merchant_key' => $rule->merchant_key,
                'transaction_kind' => $rule->transaction_kind?->value,
                'currency' => $rule->currency?->value,
                'enabled' => $rule->enabled,
            ])
            ->filter(function (array $rule) use ($search, $categoryId, $status, $kind, $currency): bool {
                if ($search !== '' && ! str_contains($this->searchable(
                    implode(' ', [
                        $rule['merchant'],
                        $rule['merchant_key'],
                        $rule['category_path'],
                        $rule['transaction_kind'] ?? 'any kind',
                        $rule['currency'] ?? 'any currency',
                        $rule['enabled'] ? 'enabled' : 'disabled',
                    ]),
                ), $search)) {
                    return false;
                }

                if ($search === '' && $categoryId !== null && $rule['category_id'] !== $categoryId) {
                    return false;
                }

                if ($status !== 'all' && $rule['enabled'] !== ($status === 'enabled')) {
                    return false;
                }

                if ($kind !== 'all' && $rule['transaction_kind'] !== ($kind === 'any' ? null : $kind)) {
                    return false;
                }

                return $currency === 'all'
                    || $rule['currency'] === ($currency === 'any' ? null : $currency);
            })
            ->sort(function (array $left, array $right) use ($sort, $direction): int {
                $leftValue = $this->sortValue($left, $sort);
                $rightValue = $this->sortValue($right, $sort);
                $comparison = is_bool($leftValue)
                    ? (int) $leftValue <=> (int) $rightValue
                    : strnatcasecmp($leftValue, (string) $rightValue);

                if ($comparison === 0) {
                    $comparison = strnatcasecmp($left['merchant_key'], $right['merchant_key']);
                }

                return $direction === 'desc' ? -$comparison : $comparison;
            })
            ->values()
            ->all());
    }

    /** @return list<array{id: int, path: string, rule_count: int}> */
    public function categoryGroups(User $owner): array
    {
        return array_values(MerchantRule::query()
            ->whereBelongsTo($owner, 'owner')
            ->select(['id', 'category_id'])
            ->with('category:id,parent_id,name', 'category.parent:id,name')
            ->get()
            ->groupBy('category_id')
            ->map(function ($rules): array {
                /** @var MerchantRule $rule */
                $rule = $rules->first();

                return [
                    'id' => $rule->category_id,
                    'path' => $this->categoryPath($rule),
                    'rule_count' => $rules->count(),
                ];
            })
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    private function categoryPath(MerchantRule $rule): string
    {
        return $rule->category->parent === null
            ? $rule->category->name
            : $rule->category->parent->name.' > '.$rule->category->name;
    }

    private function searchable(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }

    /**
     * @param  array{id: int, category_id: int, category_name: string, category_path: string, merchant: string, merchant_key: string, transaction_kind: string|null, currency: string|null, enabled: bool}  $rule
     * @param  'category'|'merchant'|'kind'|'currency'|'status'  $sort
     */
    private function sortValue(array $rule, string $sort): string|bool
    {
        return match ($sort) {
            'merchant' => $rule['merchant_key'],
            'kind' => $rule['transaction_kind'] ?? 'any',
            'currency' => $rule['currency'] ?? 'any',
            'status' => $rule['enabled'],
            default => $rule['category_path'],
        };
    }
}
