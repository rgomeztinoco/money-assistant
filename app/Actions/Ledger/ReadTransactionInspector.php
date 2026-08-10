<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ReadCategoryAssignmentProvenance;
use App\Actions\Categorization\ReadLearnedRuleCandidateFromCorrection;
use App\Actions\ReceiptReconciliation\ReadReceiptBreakdownState;
use App\Actions\Retention\ReadFinancialTrash;
use App\Models\SpendingNotificationReference;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\TransactionCorrection;
use App\Models\TransactionStateChange;
use App\Models\User;
use App\ReceiptBreakdownSetFingerprint;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\SourceReferenceSetFingerprint;
use Illuminate\Support\Str;

/**
 * @phpstan-import-type CategoryAssignmentProvenanceData from ReadCategoryAssignmentProvenance
 * @phpstan-import-type ReceiptBreakdownState from ReadReceiptBreakdownState
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
 * @phpstan-type DuplicateTransactionData array{
 *     id: int,
 *     occurred_on: string,
 *     amount_minor: string,
 *     currency: string,
 *     kind: string,
 *     merchant_description: string,
 *     category_name: string|null,
 *     revision: int,
 *     original_purchase_id: int|null,
 *     has_linked_refunds: bool,
 *     receipt_breakdown_statuses: list<string>,
 *     protects_resolved_duplicate: bool,
 *     source_reference_count: int,
 *     source_reference_fingerprint: string,
 *     receipt_breakdown_fingerprint: string
 * }
 */
class ReadTransactionInspector
{
    public function __construct(
        private ReadCategoryAssignmentProvenance $readCategoryAssignmentProvenance,
        private ReadLearnedRuleCandidateFromCorrection $readLearnedRuleCandidateFromCorrection,
        private ReadReceiptBreakdownState $readReceiptBreakdownState,
        private ReadFinancialTrash $readFinancialTrash,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     confirmed_at: string,
     *     revision: int,
     *     voided_at: string|null,
     *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
     *     review: array{
     *         category: bool,
     *         fields: list<array{name: string, label: string, value: string}>,
     *         refund_relationship_reasons: list<array{name: string, label: string}>
     *     },
     *     original_purchase: RelatedTransactionData|null,
     *     linked_refunds: list<RelatedTransactionData>,
     *     corrections: list<array{id: int, field: string, field_label: string, previous_value: string, corrected_value: string, transaction_revision: int, created_at: string|null}>,
     *     ai_category_proposal: array{id: int, revision: int, name: string, parent_path: string|null, description: string|null, examples: list<string>}|null,
     *     learned_rule_candidate: array{transaction_id: int, transaction_revision: int, category_id: int, category_name: string, merchant_pattern: string, merchant_key: string, match_mode: string, transaction_kind: string, currency: string, payment_instrument_label: null, payment_instrument_last_four: null}|null,
     *     state_changes: list<array{id: int, operation: string, result_revision: int, result_voided_at: string|null, created_at: string|null}>,
     *     source_reference_count: int,
     *     source_references: list<array{id: int, processing_outcome: string, created_at: string|null}>,
     *     duplicate_relationships: list<array{
     *         id: int,
     *         revision: int,
     *         status: string,
     *         resolved_at: string|null,
     *         survivor_transaction_id: int|null,
     *         voided_transaction_id: int|null,
     *         resolution_idempotency_key: string,
     *         reopen_idempotency_key: string,
     *         other_transaction: RelatedTransactionData,
     *         first_transaction: DuplicateTransactionData,
     *         second_transaction: DuplicateTransactionData
     *     }>,
     *     receipt_breakdown: ReceiptBreakdownState,
     *     trashed_receipt_breakdowns: list<array{deletion_id: string, revision: int, purge_after: string}>,
     *     state_change_idempotency_key: string
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
                'currentCategoryAssignment.owner:id,name',
                'currentCategoryAssignment.linkedPurchase:id,merchant_description',
                'aiCategoryProposal.parent:id,name',
                'originalPurchase:id,occurred_on,amount_minor,currency,kind,merchant_description,category_id',
                'originalPurchase.category:id,name',
                'linkedRefunds' => fn ($query) => $query
                    ->with('category:id,name')
                    ->orderByDesc('occurred_on')
                    ->orderByDesc('id'),
                'corrections' => fn ($query) => $query
                    ->orderByDesc('transaction_revision'),
                'stateChanges' => fn ($query) => $query
                    ->orderByDesc('result_revision'),
                'spendingNotificationReferences' => fn ($query) => $query
                    ->orderByDesc('created_at'),
                'receiptBreakdowns.lineItems.category:id,name',
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

        $duplicateRelationships = SuspectedDuplicate::query()
            ->whereBelongsTo($owner, 'owner')
            ->where(function ($query) use ($transaction): void {
                $query
                    ->where('first_transaction_id', $transaction->id)
                    ->orWhere('second_transaction_id', $transaction->id);
            })
            ->with([
                'firstTransaction' => fn ($query) => $query
                    ->with([
                        'category:id,name',
                        'spendingNotificationReferences:id,transaction_id',
                        'receiptBreakdowns:id,transaction_id,status,revision',
                    ])
                    ->withExists([
                        'linkedRefunds',
                        'resolvedDuplicateRelationshipsAsSurvivor',
                    ]),
                'secondTransaction' => fn ($query) => $query
                    ->with([
                        'category:id,name',
                        'spendingNotificationReferences:id,transaction_id',
                        'receiptBreakdowns:id,transaction_id,status,revision',
                    ])
                    ->withExists([
                        'linkedRefunds',
                        'resolvedDuplicateRelationshipsAsSurvivor',
                    ]),
            ])
            ->orderByDesc('created_at')
            ->get();

        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'revision' => $transaction->revision,
            'voided_at' => $transaction->voided_at?->toIso8601String(),
            'category' => $transaction->category === null
                ? null
                : [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                    'provenance' => $this->readCategoryAssignmentProvenance->handle($transaction, $owner),
                ],
            'review' => [
                'category' => ($receiptBreakdown['confirmed'] === null
                    || $receiptBreakdown['confirmed']['line_items'] === [])
                    && $transaction->category_id === null
                    || $transaction->hasProvisionalAiCategory(),
                'fields' => $reviewFields,
                'refund_relationship_reasons' => $refundRelationshipReasons,
            ],
            'original_purchase' => $transaction->originalPurchase === null
                ? null
                : $this->relatedTransactionData($transaction->originalPurchase),
            'linked_refunds' => array_values($transaction->linkedRefunds
                ->map(fn (Transaction $refund): array => $this->relatedTransactionData($refund))
                ->all()),
            'corrections' => array_values($transaction->corrections
                ->map(fn (TransactionCorrection $correction): array => [
                    'id' => $correction->id,
                    'field' => $correction->field->value,
                    'field_label' => $correction->field->label(),
                    'previous_value' => $correction->previous_value,
                    'corrected_value' => $correction->corrected_value,
                    'transaction_revision' => $correction->transaction_revision,
                    'created_at' => $correction->created_at?->toIso8601String(),
                ])
                ->all()),
            'ai_category_proposal' => $transaction->aiCategoryProposal === null
                || $transaction->aiCategoryProposal->confirmed_at !== null
                    ? null
                    : [
                        'id' => $transaction->aiCategoryProposal->id,
                        'revision' => $transaction->aiCategoryProposal->revision,
                        'name' => $transaction->aiCategoryProposal->name,
                        'parent_path' => $transaction->aiCategoryProposal->parent?->name,
                        'description' => $transaction->aiCategoryProposal->description,
                        'examples' => $transaction->aiCategoryProposal->examples,
                    ],
            'learned_rule_candidate' => $this->readLearnedRuleCandidateFromCorrection->handle($transaction),
            'state_changes' => array_values($transaction->stateChanges
                ->map(fn (TransactionStateChange $stateChange): array => [
                    'id' => $stateChange->id,
                    'operation' => $stateChange->operation->value,
                    'result_revision' => $stateChange->result_revision,
                    'result_voided_at' => $stateChange->result_voided_at?->toIso8601String(),
                    'created_at' => $stateChange->created_at?->toIso8601String(),
                ])
                ->all()),
            'source_reference_count' => $transaction->spendingNotificationReferences->count(),
            'source_references' => array_values($transaction->spendingNotificationReferences
                ->map(fn (SpendingNotificationReference $reference): array => [
                    'id' => $reference->id,
                    'processing_outcome' => $reference->processing_outcome,
                    'created_at' => $reference->created_at?->toIso8601String(),
                ])
                ->all()),
            'duplicate_relationships' => array_values($duplicateRelationships
                ->map(function (SuspectedDuplicate $relationship) use ($transaction): array {
                    $otherTransaction = $relationship->first_transaction_id === $transaction->id
                        ? $relationship->secondTransaction
                        : $relationship->firstTransaction;

                    return [
                        'id' => $relationship->id,
                        'revision' => $relationship->revision,
                        'status' => $relationship->resolved_at === null ? 'suspected' : 'resolved',
                        'resolved_at' => $relationship->resolved_at?->toIso8601String(),
                        'survivor_transaction_id' => $relationship->survivor_transaction_id,
                        'voided_transaction_id' => $relationship->voided_transaction_id,
                        'resolution_idempotency_key' => (string) Str::uuid(),
                        'reopen_idempotency_key' => (string) Str::uuid(),
                        'other_transaction' => $this->relatedTransactionData($otherTransaction),
                        'first_transaction' => $this->duplicateTransactionData($relationship->firstTransaction),
                        'second_transaction' => $this->duplicateTransactionData($relationship->secondTransaction),
                    ];
                })
                ->all()),
            'receipt_breakdown' => $receiptBreakdown,
            'trashed_receipt_breakdowns' => $this->readFinancialTrash
                ->receiptBreakdowns($owner, $transaction),
            'state_change_idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return RelatedTransactionData
     */
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

    /**
     * @return DuplicateTransactionData
     */
    private function duplicateTransactionData(Transaction $transaction): array
    {
        $receiptBreakdownStatuses = [];

        foreach ($transaction->receiptBreakdowns as $receiptBreakdown) {
            $receiptBreakdownStatuses[] = $receiptBreakdown->status;
        }

        return [
            ...$this->relatedTransactionData($transaction),
            'revision' => $transaction->revision,
            'original_purchase_id' => $transaction->original_purchase_id,
            'has_linked_refunds' => (bool) $transaction->linked_refunds_exists,
            'receipt_breakdown_statuses' => $receiptBreakdownStatuses,
            'protects_resolved_duplicate' => (bool) $transaction->resolved_duplicate_relationships_as_survivor_exists,
            'source_reference_count' => $transaction->spendingNotificationReferences->count(),
            'source_reference_fingerprint' => SourceReferenceSetFingerprint::fromIds(
                $transaction->spendingNotificationReferences->modelKeys(),
            ),
            'receipt_breakdown_fingerprint' => ReceiptBreakdownSetFingerprint::fromBreakdowns(
                $transaction->receiptBreakdowns,
            ),
        ];
    }
}
