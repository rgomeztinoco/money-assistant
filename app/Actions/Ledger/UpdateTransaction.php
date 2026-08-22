<?php

namespace App\Actions\Ledger;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\ExactInteger;
use App\IncomeSource;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\TransactionKind;
use App\TransferPurpose;
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
        MovementDirection $direction,
        string $description,
        ?IncomeSource $incomeSource,
        ?TransferPurpose $transferPurpose,
        ?string $instrumentLabel,
        ?string $instrumentLastFour,
        ?int $categoryId,
        ?int $originalSpendingId,
        bool $removeReceiptBreakdown,
    ): Transaction {
        return DB::transaction(function () use ($owner, $transaction, $occurredOn, $amountMinor, $currency, $kind, $direction, $description, $incomeSource, $transferPurpose, $instrumentLabel, $instrumentLastFour, $categoryId, $originalSpendingId, $removeReceiptBreakdown): Transaction {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $previousKind = $currentTransaction->kind;
            $previousOriginalSpendingId = $currentTransaction->original_spending_id;
            $amountChanged = $currentTransaction->amount_minor !== $amountMinor;
            $normalizedDescription = Str::squish($description);
            $remainingProvisionalFields = $this->remainingProvisionalFields(
                $currentTransaction,
                $occurredOn,
                $amountMinor,
                $currency,
                $kind,
                $normalizedDescription,
            );

            $currentTransaction->forceFill([
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'kind' => $kind,
                'direction' => $direction,
                'income_source' => $kind === TransactionKind::Income ? $incomeSource : null,
                'transfer_purpose' => $kind === TransactionKind::Transfer ? $transferPurpose : null,
                'description' => $normalizedDescription,
                'instrument_label' => filled($instrumentLabel)
                    ? Str::squish($instrumentLabel)
                    : null,
                'instrument_last_four' => $instrumentLastFour,
                'category_id' => $kind->supportsCategory() ? $categoryId : null,
                'category_assignment_provenance' => ! $kind->supportsCategory() || $categoryId === null
                    ? null
                    : CategoryAssignmentProvenance::Owner,
                'merchant_rule_id' => null,
                'original_spending_id' => $kind === TransactionKind::Refund
                    ? $originalSpendingId
                    : null,
                'provisional_fields' => $remainingProvisionalFields,
            ]);
            $currentTransaction->refund_relationship_review_reasons = $this->refundReviewReasons(
                $currentTransaction,
            );
            $currentTransaction->save();

            if ((! $kind->supportsCategory()) || ($removeReceiptBreakdown && $amountChanged)) {
                $currentTransaction->receiptBreakdown()->lockForUpdate()->first()?->delete();
            }

            $affectedSpendingIds = array_filter([
                $previousKind === TransactionKind::Spending ? $currentTransaction->id : null,
                $previousOriginalSpendingId,
                $currentTransaction->kind === TransactionKind::Spending ? $currentTransaction->id : null,
                $currentTransaction->original_spending_id,
            ]);

            foreach (array_unique($affectedSpendingIds) as $spendingId) {
                $this->refreshLinkedRefundReviewReasons($owner, $spendingId);
            }

            return $currentTransaction->refresh();
        }, 3);
    }

    /** @return list<string> */
    private function refundReviewReasons(Transaction $refund): array
    {
        if (
            $refund->kind !== TransactionKind::Refund
            || $refund->original_spending_id === null
            || $refund->voided_at !== null
        ) {
            return [];
        }

        $spending = Transaction::query()
            ->whereKey($refund->original_spending_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($spending->kind !== TransactionKind::Spending || $spending->voided_at !== null) {
            return [];
        }
        $linkedRefundTotal = ExactInteger::from((string) Transaction::query()
            ->where('original_spending_id', $spending->getKey())
            ->where('id', '<>', $refund->getKey())
            ->whereNull('voided_at')
            ->sum('amount_minor'))
            ->add(ExactInteger::from($refund->amount_minor));
        $reviewReasons = [];

        if ($linkedRefundTotal->compare(ExactInteger::from($spending->amount_minor)) === 1) {
            $reviewReasons[] = RefundRelationshipReviewReason::CumulativeRefundsExceedSpending->value;
        }

        if ($spending->receiptBreakdown()->exists()) {
            $reviewReasons[] = RefundRelationshipReviewReason::ReceiptBreakdownAllocationRequiresReview->value;
        }

        return $reviewReasons;
    }

    private function refreshLinkedRefundReviewReasons(User $owner, int $spendingId): void
    {
        $spending = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($spendingId)
            ->lockForUpdate()
            ->first();

        if ($spending === null) {
            return;
        }

        $linkedRefunds = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('original_spending_id', $spendingId)
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
            $spending->kind === TransactionKind::Spending
            && $spending->voided_at === null
        ) {
            if ($activeRefundTotal->compare(ExactInteger::from($spending->amount_minor)) === 1) {
                $reviewReasons[] = RefundRelationshipReviewReason::CumulativeRefundsExceedSpending->value;
            }

            if ($spending->receiptBreakdown()->exists()) {
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
        string $description,
    ): array {
        return array_values(array_filter(
            $transaction->provisional_fields,
            function (string $fieldName) use ($transaction, $occurredOn, $amountMinor, $currency, $kind, $description): bool {
                return ! match (ReviewableTransactionField::from($fieldName)) {
                    ReviewableTransactionField::OccurredOn => $transaction->occurred_on->toDateString() !== $occurredOn->toDateString(),
                    ReviewableTransactionField::AmountMinor => $transaction->amount_minor !== $amountMinor,
                    ReviewableTransactionField::Currency => $transaction->currency !== $currency,
                    ReviewableTransactionField::Kind => $transaction->kind !== $kind,
                    ReviewableTransactionField::Description => $transaction->description !== $description,
                };
            },
        ));
    }
}
