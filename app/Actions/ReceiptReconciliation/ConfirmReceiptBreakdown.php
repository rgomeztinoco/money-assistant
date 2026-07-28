<?php

namespace App\Actions\ReceiptReconciliation;

use App\ExactInteger;
use App\Exceptions\ReceiptBreakdownNotReconciled;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmReceiptBreakdown
{
    public function handle(
        User $owner,
        ReceiptBreakdown $breakdown,
        int $expectedRevision,
    ): ReceiptBreakdown {
        return DB::transaction(function () use ($owner, $breakdown, $expectedRevision): ReceiptBreakdown {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($breakdown->transaction_id)
                ->lockForUpdate()
                ->firstOrFail();
            $breakdowns = ReceiptBreakdown::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('transaction_id', $transaction->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $draft = $breakdowns->first(
                fn (ReceiptBreakdown $candidate): bool => $candidate->is($breakdown)
                    && $candidate->status === 'draft',
            );

            if ($draft === null) {
                throw (new ModelNotFoundException)->setModel(ReceiptBreakdown::class, [$breakdown->getKey()]);
            }

            if ($transaction->voided_at !== null || $transaction->kind !== TransactionKind::Purchase) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'Only an active purchase Transaction can confirm this purchased-item breakdown.',
                ]);
            }

            $proposal = $draft->receiptProposal()->lockForUpdate()->first();

            if ($proposal !== null
                && ($proposal->proposed_transaction['currency'] !== $transaction->currency->value
                    || $proposal->proposed_transaction['kind'] !== $transaction->kind->value)) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'The Transaction currency or kind changed after this draft was attached.',
                ]);
            }

            if ($draft->revision !== $expectedRevision) {
                throw StaleReceiptBreakdownRevision::fromBreakdown($draft);
            }

            $lineItems = $draft->lineItems()->lockForUpdate()->get();
            $categoryIds = $lineItems->pluck('category_id')->filter()->unique()->values();
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
                    'line_items' => 'Every assigned Line Item Category must still be active and owned by you.',
                ]);
            }

            $total = ExactInteger::from(0);

            foreach ($lineItems as $lineItem) {
                $total = $total->add(ExactInteger::from($lineItem->line_total_minor));
            }

            $delta = ExactInteger::from($transaction->amount_minor)->subtract($total);

            if ($delta->compare(ExactInteger::from(0)) !== 0) {
                throw new ReceiptBreakdownNotReconciled($delta->value());
            }

            $breakdowns
                ->where('status', 'confirmed')
                ->each(function (ReceiptBreakdown $confirmedBreakdown): void {
                    $confirmedBreakdown->status = 'superseded';
                    $confirmedBreakdown->revision++;
                    $confirmedBreakdown->save();
                });

            $draft->status = 'confirmed';
            $draft->confirmed_at = now()->toImmutable();
            $draft->save();

            return $draft->load('lineItems.category');
        }, 3);
    }
}
