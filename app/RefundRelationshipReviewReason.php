<?php

namespace App;

enum RefundRelationshipReviewReason: string
{
    case CumulativeRefundsExceedSpending = 'cumulative_refunds_exceed_spending';
    case ReceiptBreakdownAllocationRequiresReview = 'receipt_breakdown_allocation_requires_review';

    public function label(): string
    {
        return match ($this) {
            self::CumulativeRefundsExceedSpending => 'Linked Refunds exceed the spending',
            self::ReceiptBreakdownAllocationRequiresReview => 'Receipt Breakdown allocation requires review',
        };
    }
}
