<?php

namespace App\Actions\Categorization;

use App\Models\MerchantRule;

final class ReadMerchantRules
{
    /**
     * @return list<array{id: int, category_id: int, category_name: string, merchant: string, merchant_key: string, transaction_kind: string|null, currency: string|null, enabled: bool}>
     */
    public function handle(): array
    {
        return array_values(MerchantRule::query()
            ->select([
                'id',
                'category_id',
                'merchant',
                'merchant_key',
                'transaction_kind',
                'currency',
                'enabled',
            ])
            ->with('category:id,name')
            ->orderByDesc('enabled')
            ->orderBy('merchant_key')
            ->get()
            ->map(fn (MerchantRule $rule): array => [
                'id' => $rule->id,
                'category_id' => $rule->category_id,
                'category_name' => $rule->category->name,
                'merchant' => $rule->merchant,
                'merchant_key' => $rule->merchant_key,
                'transaction_kind' => $rule->transaction_kind?->value,
                'currency' => $rule->currency?->value,
                'enabled' => $rule->enabled,
            ])
            ->values()
            ->all());
    }
}
