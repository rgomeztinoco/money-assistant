<?php

namespace App;

enum RefundRelationshipReviewReason: string
{
    case CumulativeRefundsExceedPurchase = 'cumulative_refunds_exceed_purchase';
    case ReceiptBreakdownAllocationRequiresReview = 'receipt_breakdown_allocation_requires_review';

    public function label(): string
    {
        return match ($this) {
            self::CumulativeRefundsExceedPurchase => 'Linked Refunds exceed the purchase',
            self::ReceiptBreakdownAllocationRequiresReview => 'Receipt Breakdown allocation requires review',
        };
    }
}
