<?php

namespace App\Actions\Reporting;

use App\ExactInteger;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

final class NetSpendingAllocation
{
    /**
     * @param  Collection<int, Category>  $categoriesById
     * @return array<int|string, ExactInteger>
     */
    public function byTopLevelCategory(Transaction $transaction, Collection $categoriesById): array
    {
        $topLevelCategoryKeys = $categoriesById
            ->whereNull('parent_id')
            ->pluck('id')
            ->push('uncategorized')
            ->all();

        return collect($this->byCategory($transaction, $categoriesById))
            ->only($topLevelCategoryKeys)
            ->all();
    }

    /**
     * @param  Collection<int, Category>  $categoriesById
     * @return array<int|string, ExactInteger>
     */
    public function byCategory(Transaction $transaction, Collection $categoriesById): array
    {
        $allocations = [];
        $lineItems = $transaction->receiptBreakdown?->lineItems;

        if ($lineItems === null || $lineItems->isEmpty()) {
            $this->add(
                allocations: $allocations,
                categoryId: $transaction->category_id,
                amount: $transaction->kind->netSpendingAmount($transaction->amount_minor),
                categoriesById: $categoriesById,
            );

            return $allocations;
        }

        foreach ($lineItems as $lineItem) {
            $this->add(
                allocations: $allocations,
                categoryId: $lineItem->category_id,
                amount: $transaction->kind->netSpendingAmount($lineItem->line_total_minor),
                categoriesById: $categoriesById,
            );
        }

        return $allocations;
    }

    /**
     * @param  array<int|string, ExactInteger>  $allocations
     * @param  Collection<int, Category>  $categoriesById
     */
    private function add(
        array &$allocations,
        ?int $categoryId,
        ExactInteger $amount,
        Collection $categoriesById,
    ): void {
        $category = $categoryId === null ? null : $categoriesById->get($categoryId);

        if ($category === null) {
            $allocations['uncategorized'] = ($allocations['uncategorized'] ?? ExactInteger::from(0))
                ->add($amount);

            return;
        }

        $allocations[$category->id] = ($allocations[$category->id] ?? ExactInteger::from(0))
            ->add($amount);

        if ($category->parent_id !== null) {
            $allocations[$category->parent_id] = ($allocations[$category->parent_id] ?? ExactInteger::from(0))
                ->add($amount);
        }
    }
}
