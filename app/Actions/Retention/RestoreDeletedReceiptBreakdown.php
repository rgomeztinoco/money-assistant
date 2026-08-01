<?php

namespace App\Actions\Retention;

use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreDeletedReceiptBreakdown
{
    public function handle(User $owner, string $deletionId): ReceiptBreakdown
    {
        $transactionId = ReceiptBreakdown::query()
            ->restorableTrash()
            ->whereBelongsTo($owner, 'owner')
            ->where('deletion_id', $deletionId)
            ->soleValue('transaction_id');

        return DB::transaction(function () use ($owner, $deletionId, $transactionId): ReceiptBreakdown {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();
            $breakdown = ReceiptBreakdown::query()
                ->restorableTrash()
                ->whereBelongsTo($owner, 'owner')
                ->whereBelongsTo($transaction)
                ->where('deletion_id', $deletionId)
                ->lockForUpdate()
                ->firstOrFail();

            $breakdown->deletion_id = null;
            $breakdown->purge_after = null;
            $breakdown->restore();

            return $breakdown;
        }, 3);
    }
}
