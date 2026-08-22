<?php

namespace App\Actions\ReceiptReconciliation;

use App\CurrencyAmount;
use App\ExactInteger;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveReceiptBreakdown
{
    /**
     * @param  list<array{description: string, quantity: string|null, unit_price_minor: int|null, line_total_minor: int, category_id: int|null}>  $lineItems
     */
    public function handle(User $owner, Transaction $transaction, array $lineItems): ReceiptBreakdown
    {
        return DB::transaction(function () use ($owner, $transaction, $lineItems): ReceiptBreakdown {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentTransaction->voided_at !== null) {
                throw ValidationException::withMessages([
                    'transaction' => 'A Receipt Breakdown cannot attach to a Voided Transaction.',
                ]);
            }

            if (! $currentTransaction->kind->supportsCategory()) {
                throw ValidationException::withMessages([
                    'transaction' => 'Receipt Breakdowns are available only for Spending and Refund Transactions.',
                ]);
            }

            $normalizedLineItems = collect($lineItems)->map(function (array $lineItem): array {
                $description = Str::squish($lineItem['description']);

                if ($description === '') {
                    throw ValidationException::withMessages([
                        'line_items' => 'Every Line Item must have a description.',
                    ]);
                }

                return [
                    'description' => $description,
                    'quantity' => $lineItem['quantity'],
                    'unit_price_minor' => $lineItem['unit_price_minor'],
                    'line_total_minor' => $lineItem['line_total_minor'],
                    'category_id' => $lineItem['category_id'],
                ];
            });
            $total = $normalizedLineItems->reduce(
                fn (ExactInteger $total, array $lineItem): ExactInteger => $total->add(
                    ExactInteger::from($lineItem['line_total_minor']),
                ),
                ExactInteger::from(0),
            );
            $delta = ExactInteger::from($currentTransaction->amount_minor)->subtract($total);

            if ($delta->compare(ExactInteger::from(0)) !== 0) {
                $difference = $delta->compare(ExactInteger::from(0)) === 1
                    ? CurrencyAmount::currencyUnits($delta->value(), $currentTransaction->currency).' '.$currentTransaction->currency->value.' remaining.'
                    : CurrencyAmount::currencyUnits(
                        $delta->multiply(ExactInteger::from(-1))->value(),
                        $currentTransaction->currency,
                    ).' '.$currentTransaction->currency->value.' over the Transaction amount.';

                throw ValidationException::withMessages([
                    'line_items' => "Line Item totals must reconcile exactly. {$difference}",
                ]);
            }

            $categoryIds = $normalizedLineItems
                ->pluck('category_id')
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $activeCategoryIds = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereIn('id', $categoryIds)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->pluck('id')
                ->sort()
                ->values();

            if ($activeCategoryIds->all() !== $categoryIds->all()) {
                throw ValidationException::withMessages([
                    'line_items' => 'Every assigned Line Item Category must be active and owned by you.',
                ]);
            }

            $breakdown = ReceiptBreakdown::query()
                ->whereBelongsTo($currentTransaction)
                ->lockForUpdate()
                ->first();

            $breakdown ??= ReceiptBreakdown::query()->create([
                'transaction_id' => $currentTransaction->getKey(),
            ]);

            $breakdown->lineItems()->delete();

            foreach ($normalizedLineItems as $lineItem) {
                $breakdown->lineItems()->create([
                    'line_item_id' => (string) Str::uuid(),
                    ...$lineItem,
                ]);
            }

            $breakdown->touch();

            return $breakdown->load('lineItems.category');
        }, 3);
    }
}
