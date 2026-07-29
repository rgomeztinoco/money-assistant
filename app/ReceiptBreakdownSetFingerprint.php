<?php

namespace App;

use App\Models\ReceiptBreakdown;

final class ReceiptBreakdownSetFingerprint
{
    /**
     * @param  iterable<ReceiptBreakdown>  $receiptBreakdowns
     */
    public static function fromBreakdowns(iterable $receiptBreakdowns): string
    {
        $states = [];

        foreach ($receiptBreakdowns as $receiptBreakdown) {
            $states[] = [
                'id' => $receiptBreakdown->id,
                'revision' => $receiptBreakdown->revision,
                'status' => $receiptBreakdown->status,
            ];
        }

        usort(
            $states,
            fn (array $left, array $right): int => $left['id'] <=> $right['id'],
        );

        return hash('sha256', json_encode($states, JSON_THROW_ON_ERROR));
    }
}
