<?php

namespace App\Actions\ReceiptReconciliation;

use App\Models\ReceiptBreakdown;
use Illuminate\Validation\ValidationException;

final class EnsureReceiptBreakdownCanBeDiscarded
{
    public function handle(ReceiptBreakdown $breakdown): void
    {
        if ($breakdown->suspectedDuplicateMoves()->exists()) {
            throw ValidationException::withMessages([
                'receipt_breakdown' => 'This Receipt Breakdown belongs to reversible Suspected Duplicate history and cannot be discarded.',
            ]);
        }
    }
}
