<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\StaleReceiptBreakdownRevision;
use App\LineItemRole;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class UpdateReceiptBreakdownDraft
{
    public function __construct(
        private ResolveReceiptAdjustmentCategories $resolveAdjustmentCategories,
    ) {}

    /**
     * @param  list<array{id: string|null, description: string, role?: string, quantity?: string|null, unit_price_minor?: int|null, line_total_minor: int, category_id: int|null, related_line_item_id?: string|null}>  $lineItems
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
            $normalizedLineItems = collect($lineItems)->map(function (array $lineItem): array {
                $role = LineItemRole::tryFrom(
                    $lineItem['role'] ?? LineItemRole::PurchasedItem->value,
                );
                $quantity = $lineItem['quantity'] ?? null;

                if ($role === null
                    || ! $role->acceptsLineTotal($lineItem['line_total_minor'])
                    || ($quantity !== null
                        && preg_match('/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/D', $quantity) !== 1)
                    || ($role === LineItemRole::Unidentified && $lineItem['category_id'] !== null)) {
                    throw ValidationException::withMessages([
                        'line_items' => 'Every Line Item must have a valid role, signed total, and review context.',
                    ]);
                }

                return [
                    ...$lineItem,
                    'role' => $role,
                    'quantity' => $quantity,
                    'unit_price_minor' => $lineItem['unit_price_minor'] ?? null,
                    'related_line_item_id' => $lineItem['related_line_item_id'] ?? null,
                ];
            });
            $submittedIds = $normalizedLineItems->pluck('id')->filter();

            if ($submittedIds->duplicates()->isNotEmpty()
                || $submittedIds->diff($currentLineItems->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'line_items' => 'Every retained Line Item identity must belong to this draft and appear once.',
                ]);
            }

            $normalizedLineItems = collect($this->resolveAdjustmentCategories->handle(
                $normalizedLineItems->values()->all(),
            ));

            $categoryIds = $normalizedLineItems
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

            $draft->lineItems()
                ->whereNotIn('line_item_id', $submittedIds)
                ->delete();

            foreach ($normalizedLineItems as $lineItem) {
                $attributes = [
                    'description' => $lineItem['description'],
                    'role' => $lineItem['role'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price_minor' => $lineItem['unit_price_minor'],
                    'line_total_minor' => $lineItem['line_total_minor'],
                    'category_id' => $lineItem['category_id'],
                    'related_line_item_id' => $lineItem['related_line_item_id'],
                    'requires_review' => $lineItem['role'] === LineItemRole::Unidentified,
                ];

                if ($lineItem['id'] === null) {
                    $draft->lineItems()->create([
                        'line_item_id' => (string) Str::uuid(),
                        ...$attributes,
                    ]);

                    continue;
                }

                $currentLineItem = $currentLineItems->get($lineItem['id']);

                if ($currentLineItem === null) {
                    throw new LogicException('A validated Line Item could not be loaded.');
                }

                $currentLineItem->update($attributes);
            }

            $draft->revision++;
            $draft->save();

            return $draft->load('lineItems.category');
        }, 3);
    }
}
