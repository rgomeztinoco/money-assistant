<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class UpdateReceiptBreakdownDraft
{
    /**
     * @param  list<array{id: string|null, description: string, line_total_minor: int, category_id: int|null}>  $lineItems
     */
    public function handle(
        User $owner,
        ReceiptBreakdown $breakdown,
        int $expectedRevision,
        array $lineItems,
    ): ReceiptBreakdown {
        return DB::transaction(function () use ($owner, $breakdown, $expectedRevision, $lineItems): ReceiptBreakdown {
            $draft = ReceiptBreakdown::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($breakdown->getKey())
                ->where('status', 'draft')
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->revision !== $expectedRevision) {
                throw StaleReceiptBreakdownRevision::fromBreakdown($draft);
            }

            $currentLineItems = $draft->lineItems()
                ->lockForUpdate()
                ->get()
                ->keyBy('line_item_id');
            $submittedIds = collect($lineItems)->pluck('id')->filter();

            if ($submittedIds->duplicates()->isNotEmpty()
                || $submittedIds->diff($currentLineItems->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'line_items' => 'Every retained Line Item identity must belong to this draft and appear once.',
                ]);
            }

            $draft->lineItems()
                ->whereNotIn('line_item_id', $submittedIds)
                ->delete();

            $categoryIds = collect($lineItems)
                ->pluck('category_id')
                ->filter()
                ->unique()
                ->values();
            $activeCategoryIds = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereIn('id', $categoryIds)
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            sort($activeCategoryIds);

            if ($activeCategoryIds !== $categoryIds->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'line_items' => 'Every assigned Line Item Category must be active and owned by you.',
                ]);
            }

            foreach ($lineItems as $lineItem) {
                if ($lineItem['id'] === null) {
                    $draft->lineItems()->create([
                        'line_item_id' => (string) Str::uuid(),
                        'description' => $lineItem['description'],
                        'line_total_minor' => $lineItem['line_total_minor'],
                        'category_id' => $lineItem['category_id'],
                    ]);

                    continue;
                }

                $currentLineItem = $currentLineItems->get($lineItem['id']);

                if ($currentLineItem === null) {
                    throw new LogicException('A validated Line Item could not be loaded.');
                }

                $currentLineItem->update([
                    'description' => $lineItem['description'],
                    'line_total_minor' => $lineItem['line_total_minor'],
                    'category_id' => $lineItem['category_id'],
                ]);
            }

            $draft->revision++;
            $draft->save();

            return $draft->load('lineItems.category');
        }, 3);
    }
}
