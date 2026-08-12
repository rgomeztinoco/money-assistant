<?php

namespace App\Actions\Ledger;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateTransaction
{
    public function handle(
        User $owner,
        Transaction $transaction,
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $merchantDescription,
        ?string $paymentInstrumentLabel,
        ?string $paymentInstrumentLastFour,
        ?int $categoryId,
        ?int $originalPurchaseId,
        bool $removeReceiptBreakdown,
    ): Transaction {
        return DB::transaction(function () use ($owner, $transaction, $occurredOn, $amountMinor, $currency, $kind, $merchantDescription, $paymentInstrumentLabel, $paymentInstrumentLastFour, $categoryId, $originalPurchaseId, $removeReceiptBreakdown): Transaction {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $previousKind = $currentTransaction->kind;
            $previousOriginalPurchaseId = $currentTransaction->original_purchase_id;
            $amountChanged = $currentTransaction->amount_minor !== $amountMinor;
            $normalizedMerchantDescription = Str::squish($merchantDescription);
            $remainingProvisionalFields = $this->remainingProvisionalFields(
                $currentTransaction,
                $occurredOn,
                $amountMinor,
                $currency,
                $kind,
                $normalizedMerchantDescription,
            );

            $currentTransaction->forceFill([
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'kind' => $kind,
                'merchant_description' => $normalizedMerchantDescription,
                'payment_instrument_label' => filled($paymentInstrumentLabel)
                    ? Str::squish($paymentInstrumentLabel)
                    : null,
                'payment_instrument_last_four' => $paymentInstrumentLastFour,
                'category_id' => $categoryId,
                'category_assignment_provenance' => $categoryId === null
                    ? null
                    : CategoryAssignmentProvenance::Owner,
                'merchant_rule_id' => null,
                'original_purchase_id' => $kind === TransactionKind::Refund
                    ? $originalPurchaseId
                    : null,
                'provisional_fields' => $remainingProvisionalFields,
            ]);
            $currentTransaction->refund_relationship_review_reasons = $this->refundReviewReasons(
                $currentTransaction,
            );
            $currentTransaction->save();

            if ($removeReceiptBreakdown && $amountChanged) {
                $currentTransaction->receiptBreakdown()->lockForUpdate()->first()?->delete();
            }

            $affectedPurchaseIds = array_filter([
                $previousKind === TransactionKind::Purchase ? $currentTransaction->id : null,
                $previousOriginalPurchaseId,
                $currentTransaction->kind === TransactionKind::Purchase ? $currentTransaction->id : null,
                $currentTransaction->original_purchase_id,
            ]);

            foreach (array_unique($affectedPurchaseIds) as $purchaseId) {
                $this->refreshLinkedRefundReviewReasons($owner, $purchaseId);
            }

            return $currentTransaction->refresh();
        }, 3);
    }

    /** @return list<string> */
    private function refundReviewReasons(Transaction $refund): array
    {
        if (
            $refund->kind !== TransactionKind::Refund
            || $refund->original_purchase_id === null
            || $refund->voided_at !== null
        ) {
            return [];
        }

        $purchase = Transaction::query()
            ->whereKey($refund->original_purchase_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($purchase->kind !== TransactionKind::Purchase || $purchase->voided_at !== null) {
            return [];
        }
        $linkedRefundTotal = ExactInteger::from((string) Transaction::query()
            ->where('original_purchase_id', $purchase->getKey())
            ->where('id', '<>', $refund->getKey())
            ->whereNull('voided_at')
            ->sum('amount_minor'))
            ->add(ExactInteger::from($refund->amount_minor));
        $reviewReasons = [];

        if ($linkedRefundTotal->compare(ExactInteger::from($purchase->amount_minor)) === 1) {
            $reviewReasons[] = RefundRelationshipReviewReason::CumulativeRefundsExceedPurchase->value;
        }

        if ($purchase->receiptBreakdown()->exists()) {
            $reviewReasons[] = RefundRelationshipReviewReason::ReceiptBreakdownAllocationRequiresReview->value;
        }

        return $reviewReasons;
    }

    private function refreshLinkedRefundReviewReasons(User $owner, int $purchaseId): void
    {
        $purchase = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($purchaseId)
            ->lockForUpdate()
            ->first();

        if ($purchase === null) {
            return;
        }

        $linkedRefunds = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('original_purchase_id', $purchaseId)
            ->lockForUpdate()
            ->get();
        $activeRefundTotal = ExactInteger::from(0);

        foreach ($linkedRefunds->whereNull('voided_at') as $linkedRefund) {
            $activeRefundTotal = $activeRefundTotal->add(
                ExactInteger::from($linkedRefund->amount_minor),
            );
        }

        $reviewReasons = [];

        if (
            $purchase->kind === TransactionKind::Purchase
            && $purchase->voided_at === null
        ) {
            if ($activeRefundTotal->compare(ExactInteger::from($purchase->amount_minor)) === 1) {
                $reviewReasons[] = RefundRelationshipReviewReason::CumulativeRefundsExceedPurchase->value;
            }

            if ($purchase->receiptBreakdown()->exists()) {
                $reviewReasons[] = RefundRelationshipReviewReason::ReceiptBreakdownAllocationRequiresReview->value;
            }
        }

        foreach ($linkedRefunds as $linkedRefund) {
            $linkedRefund->refund_relationship_review_reasons = $linkedRefund->voided_at === null
                ? $reviewReasons
                : [];

            if ($linkedRefund->isDirty('refund_relationship_review_reasons')) {
                $linkedRefund->save();
            }
        }
    }

    /** @return list<string> */
    private function remainingProvisionalFields(
        Transaction $transaction,
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $merchantDescription,
    ): array {
        return array_values(array_filter(
            $transaction->provisional_fields,
            function (string $fieldName) use ($transaction, $occurredOn, $amountMinor, $currency, $kind, $merchantDescription): bool {
                return ! match (ReviewableTransactionField::from($fieldName)) {
                    ReviewableTransactionField::OccurredOn => $transaction->occurred_on->toDateString() !== $occurredOn->toDateString(),
                    ReviewableTransactionField::AmountMinor => $transaction->amount_minor !== $amountMinor,
                    ReviewableTransactionField::Currency => $transaction->currency !== $currency,
                    ReviewableTransactionField::Kind => $transaction->kind !== $kind,
                    ReviewableTransactionField::MerchantDescription => $transaction->merchant_description !== $merchantDescription,
                };
            },
        ));
    }
}
