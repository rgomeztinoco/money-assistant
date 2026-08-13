<?php

namespace App\Actions\ReceiptReconciliation;

use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class RemoveReceiptBreakdown
{
    public function handle(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $currentTransaction = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            ReceiptBreakdown::query()
                ->whereBelongsTo($currentTransaction)
                ->lockForUpdate()
                ->firstOrFail()
                ->delete();
        }, 3);
    }
}
