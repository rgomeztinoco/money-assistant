<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DemoteConfirmedReceiptBreakdown
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
                ->whereBelongsTo($transaction)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $confirmedBreakdown = $breakdowns->first(
                fn (ReceiptBreakdown $candidate): bool => $candidate->is($breakdown)
                    && $candidate->status === 'confirmed',
            );

            if ($confirmedBreakdown === null) {
                throw (new ModelNotFoundException)->setModel(ReceiptBreakdown::class, [$breakdown->getKey()]);
            }

            if ($confirmedBreakdown->revision !== $expectedRevision) {
                throw StaleReceiptBreakdownRevision::fromBreakdown($confirmedBreakdown);
            }

            if ($breakdowns->contains('status', 'draft')) {
                throw ValidationException::withMessages([
                    'receipt_breakdown' => 'Discard the current draft before removing the confirmed Receipt Breakdown.',
                ]);
            }

            $confirmedBreakdown->status = 'draft';
            $confirmedBreakdown->confirmed_at = null;
            $confirmedBreakdown->revision++;
            $confirmedBreakdown->save();

            return $confirmedBreakdown->load('lineItems.category');
        }, 3);
    }
}
