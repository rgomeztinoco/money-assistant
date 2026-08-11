<?php

namespace App;

use App\Models\ReceiptBreakdown;

final class ReceiptBreakdownFingerprint
{
    public static function fromBreakdown(?ReceiptBreakdown $receiptBreakdown): string
    {
        $state = $receiptBreakdown === null
            ? null
            : [
                'id' => $receiptBreakdown->id,
                'updated_at' => $receiptBreakdown->updated_at?->toIso8601String(),
            ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
