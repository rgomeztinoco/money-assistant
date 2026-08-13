<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ReadCategoryAssignmentProvenance;
use App\Actions\ReceiptReconciliation\ReadReceiptBreakdownState;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;

/**
 * @phpstan-import-type CategoryAssignmentProvenanceData from ReadCategoryAssignmentProvenance
 * @phpstan-import-type ReceiptBreakdownData from ReadReceiptBreakdownState
 *
 * @phpstan-type RelatedTransactionData array{
 *     id: int,
 *     occurred_on: string,
 *     amount_minor: string,
 *     currency: string,
 *     kind: string,
 *     merchant_description: string,
 *     category_name: string|null
 * }
 */
class ReadTransactionInspector
{
    public function __construct(
        private ReadCategoryAssignmentProvenance $readCategoryAssignmentProvenance,
        private ReadReceiptBreakdownState $readReceiptBreakdownState,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     payment_instrument_label: string|null,
     *     payment_instrument_last_four: string|null,
     *     confirmed_at: string,
     *     voided_at: string|null,
     *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
     *     review: array{
     *         category: bool,
     *         fields: list<array{name: string, label: string, value: string}>,
     *         refund_relationship_reasons: list<array{name: string, label: string}>
     *     },
     *     original_purchase: RelatedTransactionData|null,
     *     linked_refunds: list<RelatedTransactionData>,
     *     source_reference_count: int,
     *     source_references: list<array{id: int, processing_outcome: string, created_at: string|null}>,
     *     receipt_breakdown: ReceiptBreakdownData|null,
     *     purchase_options: list<array{id: int, occurred_on: string, merchant_description: string, currency: string}>
     * }|null
     */
    public function handle(User $owner, ?int $transactionId): ?array
    {
        if ($transactionId === null) {
            return null;
        }

        $transaction = Transaction::query()
            ->with([
                'category:id,name',
                'originalPurchase:id,occurred_on,amount_minor,currency,kind,merchant_description,category_id',
                'originalPurchase.category:id,name',
                'linkedRefunds' => fn ($query) => $query
                    ->with('category:id,name')
                    ->orderByDesc('occurred_on')
                    ->orderByDesc('id'),
                'spendingNotificationReferences' => fn ($query) => $query
                    ->orderByDesc('created_at'),
                'receiptBreakdown.lineItems.category:id,name',
            ])
            ->find($transactionId);

        if ($transaction === null) {
            return null;
        }

        $receiptBreakdown = $this->readReceiptBreakdownState->handle($transaction);
        $reviewFields = [];

        foreach ($transaction->provisional_fields as $fieldName) {
            $field = ReviewableTransactionField::from($fieldName);
            $reviewFields[] = [
                'name' => $field->value,
                'label' => $field->label(),
                'value' => $field->valueFor($transaction),
            ];
        }

        $refundRelationshipReasons = [];

        foreach ($transaction->refund_relationship_review_reasons as $reasonValue) {
            $reason = RefundRelationshipReviewReason::from($reasonValue);
            $refundRelationshipReasons[] = [
                'name' => $reason->value,
                'label' => $reason->label(),
            ];
        }

        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'payment_instrument_label' => $transaction->payment_instrument_label,
            'payment_instrument_last_four' => $transaction->payment_instrument_last_four,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'voided_at' => $transaction->voided_at?->toIso8601String(),
            'category' => $transaction->category === null
                ? null
                : [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                    'provenance' => $this->readCategoryAssignmentProvenance->handle($transaction, $owner),
                ],
            'review' => [
                'category' => ($receiptBreakdown === null || $receiptBreakdown['line_items'] === [])
                    && $transaction->category_id === null,
                'fields' => $reviewFields,
                'refund_relationship_reasons' => $refundRelationshipReasons,
            ],
            'original_purchase' => $transaction->originalPurchase === null
                ? null
                : $this->relatedTransactionData($transaction->originalPurchase),
            'linked_refunds' => array_values($transaction->linkedRefunds
                ->map(fn (Transaction $refund): array => $this->relatedTransactionData($refund))
                ->all()),
            'source_reference_count' => $transaction->spendingNotificationReferences->count(),
            'source_references' => array_values($transaction->spendingNotificationReferences
                ->map(fn (SpendingNotificationReference $reference): array => [
                    'id' => $reference->id,
                    'processing_outcome' => $reference->processing_outcome,
                    'created_at' => $reference->created_at?->toIso8601String(),
                ])
                ->all()),
            'receipt_breakdown' => $receiptBreakdown,
            'purchase_options' => array_values(Transaction::query()
                ->whereNull('voided_at')
                ->where('kind', 'purchase')
                ->whereKeyNot($transaction->getKey())
                ->orderByDesc('occurred_on')
                ->orderByDesc('id')
                ->get(['id', 'occurred_on', 'merchant_description', 'currency'])
                ->map(fn (Transaction $purchase): array => [
                    'id' => $purchase->id,
                    'occurred_on' => $purchase->occurred_on->toDateString(),
                    'merchant_description' => $purchase->merchant_description,
                    'currency' => $purchase->currency->value,
                ])
                ->all()),
        ];
    }

    /** @return RelatedTransactionData */
    private function relatedTransactionData(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'category_name' => $transaction->category?->name,
        ];
    }
}
