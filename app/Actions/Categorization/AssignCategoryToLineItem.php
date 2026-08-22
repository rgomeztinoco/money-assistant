<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use App\Models\LineItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignCategoryToLineItem
{
    public function handle(User $owner, LineItem $lineItem, int $categoryId): LineItem
    {
        return DB::transaction(function () use ($owner, $lineItem, $categoryId): LineItem {
            $currentLineItem = LineItem::query()
                ->whereKey($lineItem->getKey())
                ->whereHas('receiptBreakdown', fn ($query) => $query
                    ->whereHas('transaction', fn ($query) => $query
                        ->whereBelongsTo($owner, 'owner')))
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentLineItem->category_id !== null) {
                throw ValidationException::withMessages([
                    'category_id' => 'This Line Item already has a Category.',
                ]);
            }

            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'category_id' => 'Choose an active Category owned by you.',
                ]);
            }

            $currentLineItem->category_id = $category->id;
            $currentLineItem->save();

            return $currentLineItem;
        }, 3);
    }
}
