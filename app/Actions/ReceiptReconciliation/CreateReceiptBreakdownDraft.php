<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\StaleTransactionRevision;
use App\LineItemRole;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateReceiptBreakdownDraft
{
    /**
     * @param  list<array{id: null, description: string, role?: string, quantity?: string|null, unit_price_minor?: int|null, line_total_minor: int, category_id: int|null, related_line_item_id?: null}>  $lineItems
     */
    public function handle(
        User $owner,
        Transaction $transaction,
        int $expectedTransactionRevision,
        array $lineItems,
    ): ReceiptBreakdown {
        return DB::transaction(function () use (
            $owner,
            $transaction,
            $expectedTransactionRevision,
            $lineItems,
        ): ReceiptBreakdown {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentTransaction->revision !== $expectedTransactionRevision) {
                throw StaleTransactionRevision::fromTransaction($currentTransaction);
            }

            if ($currentTransaction->voided_at !== null) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'A Receipt Breakdown cannot attach to a Voided Transaction.',
                ]);
            }

            if (ReceiptBreakdown::withTrashed()
                ->where('transaction_id', $currentTransaction->getKey())
                ->where('status', 'draft')
                ->exists()) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'This Transaction already has a draft Receipt Breakdown, including recoverable trash.',
                ]);
            }

            if ($lineItems === []) {
                throw ValidationException::withMessages([
                    'line_items' => 'A Receipt Breakdown must contain at least one Line Item.',
                ]);
            }

            $normalizedLineItems = collect($lineItems)->map(function (array $lineItem): array {
                $role = LineItemRole::tryFrom(
                    $lineItem['role'] ?? LineItemRole::PurchasedItem->value,
                );
                $quantity = $lineItem['quantity'] ?? null;
                $description = Str::squish($lineItem['description']);

                if ($description === ''
                    || mb_strlen($description) > 255
                    || $role === null
                    || ! $role->acceptsLineTotal($lineItem['line_total_minor'])
                    || ($quantity !== null
                        && preg_match('/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/D', $quantity) !== 1)
                    || ($role === LineItemRole::Unidentified && $lineItem['category_id'] !== null)) {
                    throw ValidationException::withMessages([
                        'line_items' => 'Every Line Item must have valid manual-entry details.',
                    ]);
                }

                return [
                    'description' => $description,
                    'role' => $role,
                    'quantity' => $quantity,
                    'unit_price_minor' => $lineItem['unit_price_minor'] ?? null,
                    'line_total_minor' => $lineItem['line_total_minor'],
                    'category_id' => $lineItem['category_id'],
                    'requires_review' => $role === LineItemRole::Unidentified,
                ];
            });
            $categoryIds = $normalizedLineItems
                ->pluck('category_id')
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $activeCategoryIds = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereIn('id', $categoryIds)
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->pluck('id')
                ->sort()
                ->values();

            if ($activeCategoryIds->all() !== $categoryIds->all()) {
                throw ValidationException::withMessages([
                    'line_items' => 'Every assigned Line Item Category must be active and owned by you.',
                ]);
            }

            $breakdown = ReceiptBreakdown::query()->create([
                'user_id' => $owner->getKey(),
                'transaction_id' => $currentTransaction->getKey(),
                'status' => 'draft',
                'revision' => 1,
            ]);

            foreach ($normalizedLineItems as $lineItem) {
                $breakdown->lineItems()->create([
                    'line_item_id' => (string) Str::uuid(),
                    ...$lineItem,
                ]);
            }

            return $breakdown->load('lineItems.category');
        }, 3);
    }
}
