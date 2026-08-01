<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DiscardReceiptBreakdownDraft
{
    public function handle(
        User $owner,
        ReceiptBreakdown $breakdown,
        int $expectedRevision,
    ): void {
        DB::transaction(function () use ($owner, $breakdown, $expectedRevision): void {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($breakdown->transaction_id)
                ->lockForUpdate()
                ->firstOrFail();
            $draft = ReceiptBreakdown::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereBelongsTo($transaction)
                ->whereKey($breakdown->getKey())
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw (new ModelNotFoundException)->setModel(ReceiptBreakdown::class, [$breakdown->getKey()]);
            }

            if ($draft->revision !== $expectedRevision) {
                throw StaleReceiptBreakdownRevision::fromBreakdown($draft);
            }

            if ($draft->suspectedDuplicateMoves()->exists()) {
                throw ValidationException::withMessages([
                    'receipt_breakdown' => 'This Receipt Breakdown belongs to reversible Suspected Duplicate history and cannot be discarded.',
                ]);
            }

            $draft->moveToFinancialTrash();
        }, 3);
    }
}
