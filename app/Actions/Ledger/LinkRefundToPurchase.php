<?php

namespace App\Actions\Ledger;

use App\CategoryAssignmentProvenance;
use App\ExactInteger;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Category;
use App\Models\CategoryAssignment;
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
        int $expectedRevision,
    ): Transaction {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('The expected Transaction revision must be positive.');
        }

        return DB::transaction(function () use (
            $owner,
            $refund,
            $purchase,
            $expectedRevision,
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

            if ($currentRefund->revision !== $expectedRevision) {
                throw StaleTransactionRevision::fromTransaction($currentRefund);
            }

            $this->ensureTransactionsCanBeLinked($currentRefund, $currentPurchase);

            $linkedRefundTotal = ExactInteger::from((string) Transaction::query()
                ->where('original_purchase_id', $currentPurchase->getKey())
                ->whereNull('voided_at')
                ->sum('amount_minor'))
                ->add(ExactInteger::from($currentRefund->amount_minor));
            $hasReceiptBreakdown = $currentPurchase
                ->receiptBreakdowns()
                ->exists();
            $activePurchaseCategory = $currentPurchase->category_id === null
                ? null
                : Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($currentPurchase->category_id)
                    ->whereNull('retired_at')
                    ->lockForUpdate()
                    ->first();

            $currentRefund->original_purchase_id = $currentPurchase->getKey();

            $categoryAssignmentApplied = false;
            $previousCategoryId = $currentRefund->category_id;

            if (
                ! $hasReceiptBreakdown
                && $activePurchaseCategory !== null
                && CategoryAssignmentProvenance::LinkedRefund->canReplace(
                    $currentRefund->category_assignment_provenance,
                )
            ) {
                $currentRefund->category_id = $activePurchaseCategory->id;
                $currentRefund->category_assignment_provenance = CategoryAssignmentProvenance::LinkedRefund;
                $categoryAssignmentApplied = true;
            }

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
            $currentRefund->revision++;
            $currentRefund->save();

            if ($categoryAssignmentApplied) {
                CategoryAssignment::create([
                    'user_id' => $owner->getKey(),
                    'transaction_id' => $currentRefund->getKey(),
                    'category_id' => $activePurchaseCategory->id,
                    'previous_category_id' => $previousCategoryId,
                    'source' => CategoryAssignmentProvenance::LinkedRefund,
                    'transaction_revision' => $currentRefund->revision,
                    'linked_purchase_id' => $currentPurchase->getKey(),
                ]);
            }

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

        if ($purchase->kind !== TransactionKind::Purchase) {
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
