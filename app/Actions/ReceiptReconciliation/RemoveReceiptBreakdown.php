<?php

namespace App\Actions\ReceiptReconciliation;

use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RemoveReceiptBreakdown
{
    public function handle(User $owner, Transaction $transaction): void
    {
        DB::transaction(function () use ($owner, $transaction): void {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
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
