<?php

namespace App\Actions\Ledger;

use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LinkRefundToPurchase
{
    public function handle(
        User $owner,
        Transaction $refund,
        Transaction $purchase,
    ): Transaction {
        return DB::transaction(function () use (
            $owner,
            $refund,
            $purchase,
        ): Transaction {
            $currentPurchase = Transaction::query()
                ->whereKey($purchase->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            $currentRefund = Transaction::query()
                ->whereKey($refund->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureTransactionsCanBeLinked($currentRefund, $currentPurchase);

            $linkedRefundTotal = ExactInteger::from((string) Transaction::query()
                ->where('original_purchase_id', $currentPurchase->getKey())
                ->whereNull('voided_at')
                ->sum('amount_minor'))
                ->add(ExactInteger::from($currentRefund->amount_minor));
            $hasReceiptBreakdown = $currentPurchase
                ->receiptBreakdown()
                ->exists();
            $currentRefund->original_purchase_id = $currentPurchase->getKey();

            $reviewReasons = [];

            if (
                $linkedRefundTotal->compare(
                    ExactInteger::from($currentPurchase->amount_minor),
                ) === 1
            ) {
                $reviewReasons[] = RefundRelationshipReviewReason::CumulativeRefundsExceedPurchase->value;
            }

            if ($hasReceiptBreakdown) {
                $reviewReasons[] = RefundRelationshipReviewReason::ReceiptBreakdownAllocationRequiresReview->value;
            }

            $currentRefund->refund_relationship_review_reasons = $reviewReasons;
            $currentRefund->save();

            return $currentRefund;
        }, 3);
    }

    private function ensureTransactionsCanBeLinked(
        Transaction $refund,
        Transaction $purchase,
    ): void {
        if ($refund->kind !== TransactionKind::Refund) {
            throw new InvalidArgumentException('Only a Refund can link to an original purchase.');
        }

        if ($purchase->kind !== TransactionKind::Spending) {
            throw new InvalidArgumentException('A Refund can link only to a purchase.');
        }

        if ($refund->is($purchase)) {
            throw new InvalidArgumentException('A Transaction cannot link to itself.');
        }

        if ($refund->currency !== $purchase->currency) {
            throw new InvalidArgumentException('A Refund and its original purchase must use the same currency.');
        }

        if ($refund->voided_at !== null || $purchase->voided_at !== null) {
            throw new InvalidArgumentException('Voided Transactions cannot be linked.');
        }

        if ($refund->original_purchase_id !== null) {
            throw new InvalidArgumentException('This Refund is already linked to a purchase.');
        }
    }
}
