<?php

namespace App\Actions\Ledger;

use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LinkRefundToSpending
{
    public function handle(
        User $owner,
        Transaction $refund,
        Transaction $spending,
    ): Transaction {
        return DB::transaction(function () use (
            $owner,
            $refund,
            $spending,
        ): Transaction {
            $currentSpending = Transaction::query()
                ->whereKey($spending->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            $currentRefund = Transaction::query()
                ->whereKey($refund->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureTransactionsCanBeLinked($currentRefund, $currentSpending);

            $linkedRefundTotal = ExactInteger::from((string) Transaction::query()
                ->where('original_spending_id', $currentSpending->getKey())
                ->whereNull('voided_at')
                ->sum('amount_minor'))
                ->add(ExactInteger::from($currentRefund->amount_minor));
            $hasReceiptBreakdown = $currentSpending
                ->receiptBreakdown()
                ->exists();
            $currentRefund->original_spending_id = $currentSpending->getKey();

            $reviewReasons = [];

            if (
                $linkedRefundTotal->compare(
                    ExactInteger::from($currentSpending->amount_minor),
                ) === 1
            ) {
                $reviewReasons[] = RefundRelationshipReviewReason::CumulativeRefundsExceedSpending->value;
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
        Transaction $spending,
    ): void {
        if ($refund->kind !== TransactionKind::Refund) {
            throw new InvalidArgumentException('Only a Refund can link to an original spending.');
        }

        if ($spending->kind !== TransactionKind::Spending) {
            throw new InvalidArgumentException('A Refund can link only to a spending.');
        }

        if ($refund->is($spending)) {
            throw new InvalidArgumentException('A Transaction cannot link to itself.');
        }

        if ($refund->currency !== $spending->currency) {
            throw new InvalidArgumentException('A Refund and its original spending must use the same currency.');
        }

        if ($refund->voided_at !== null || $spending->voided_at !== null) {
            throw new InvalidArgumentException('Voided Transactions cannot be linked.');
        }

        if ($refund->original_spending_id !== null) {
            throw new InvalidArgumentException('This Refund is already linked to a spending.');
        }
    }
}
