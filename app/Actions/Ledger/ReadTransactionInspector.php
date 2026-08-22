<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ReadCategoryAssignmentProvenance;
use App\Actions\ReceiptReconciliation\ReadReceiptBreakdownState;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\TransactionKind;

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
 *     description: string,
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
     *     direction: string,
     *     income_source: string|null,
     *     transfer_purpose: string|null,
     *     description: string,
     *     instrument_label: string|null,
     *     instrument_last_four: string|null,
     *     confirmed_at: string,
     *     voided_at: string|null,
     *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
     *     review: array{
     *         category: bool,
     *         fields: list<array{name: string, label: string, value: string}>,
     *         refund_relationship_reasons: list<array{name: string, label: string}>
     *     },
     *     original_spending: RelatedTransactionData|null,
     *     linked_refunds: list<RelatedTransactionData>,
     *     source_reference_count: int,
     *     source_references: list<array{id: int, processing_outcome: string, created_at: string|null}>,
     *     receipt_breakdown: ReceiptBreakdownData|null,
     *     spending_options: list<array{id: int, occurred_on: string, description: string, currency: string}>
     * }|null
     */
    public function handle(User $owner, ?int $transactionId): ?array
    {
        if ($transactionId === null) {
            return null;
        }

        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->with([
                'category:id,name',
                'originalSpending:id,occurred_on,amount_minor,currency,kind,description,category_id',
                'originalSpending.category:id,name',
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
            'direction' => $transaction->direction->value,
            'income_source' => $transaction->income_source?->value,
            'transfer_purpose' => $transaction->transfer_purpose?->value,
            'description' => $transaction->description,
            'instrument_label' => $transaction->instrument_label,
            'instrument_last_four' => $transaction->instrument_last_four,
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
                'category' => $transaction->kind->supportsCategory()
                    && ($receiptBreakdown === null || $receiptBreakdown['line_items'] === [])
                    && $transaction->category_id === null,
                'fields' => $reviewFields,
                'refund_relationship_reasons' => $refundRelationshipReasons,
            ],
            'original_spending' => $transaction->originalSpending === null
                ? null
                : $this->relatedTransactionData($transaction->originalSpending),
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
            'spending_options' => array_values(Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereNull('voided_at')
                ->where('kind', TransactionKind::Spending)
                ->whereKeyNot($transaction->getKey())
                ->orderByDesc('occurred_on')
                ->orderByDesc('id')
                ->get(['id', 'occurred_on', 'description', 'currency'])
                ->map(fn (Transaction $spending): array => [
                    'id' => $spending->id,
                    'occurred_on' => $spending->occurred_on->toDateString(),
                    'description' => $spending->description,
                    'currency' => $spending->currency->value,
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
            'description' => $transaction->description,
            'category_name' => $transaction->category?->name,
        ];
    }
}
