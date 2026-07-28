<?php

namespace App\Actions\Ledger;

use App\ExactInteger;
use App\Models\LineItem;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\SourceReferenceSetFingerprint;
use Illuminate\Support\Str;

class ReadReviewQueue
{
    /**
     * @return array{
     *     unresolved_field_count: int,
     *     unresolved_category_count: int,
     *     unresolved_refund_relationship_count: int,
     *     unresolved_suspected_duplicate_count: int,
     *     transactions: list<array{
     *         id: int,
     *         revision: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         merchant_description: string,
     *         confirmed_at: string,
     *         fields: list<array{name: string, label: string, value: string}>
     *     }>,
     *     refund_relationships: list<array{
     *         refund: array{id: int, merchant_description: string, amount_minor: string, currency: string, category_name: string|null},
     *         purchase: array{id: int, merchant_description: string, amount_minor: string, currency: string},
     *         reason: string,
     *         reason_label: string,
     *         linked_refund_total_minor: string,
     *         overage_minor: string
     *     }>,
     *     suspected_duplicates: list<array{
     *         id: int,
     *         revision: int,
     *         resolution_idempotency_key: string,
     *         first_transaction: array{
     *             id: int,
     *             revision: int,
     *             occurred_on: string,
     *             amount_minor: string,
     *             currency: string,
     *             kind: string,
     *             merchant_description: string,
     *             category_name: string|null,
     *             original_purchase_id: int|null,
     *             has_linked_refunds: bool,
     *             has_receipt_breakdown: bool,
     *             protects_resolved_duplicate: bool,
     *             source_reference_count: int,
     *             source_reference_fingerprint: string
     *         },
     *         second_transaction: array{
     *             id: int,
     *             revision: int,
     *             occurred_on: string,
     *             amount_minor: string,
     *             currency: string,
     *             kind: string,
     *             merchant_description: string,
     *             category_name: string|null,
     *             original_purchase_id: int|null,
     *             has_linked_refunds: bool,
     *             has_receipt_breakdown: bool,
     *             protects_resolved_duplicate: bool,
     *             source_reference_count: int,
     *             source_reference_fingerprint: string
     *         }
     *     }>
     * }
     */
    public function handle(User $owner): array
    {
        $unresolvedCategoryCount = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereNull('category_id')
            ->whereDoesntHave('receiptBreakdowns', fn ($query) => $query
                ->where('status', 'confirmed')
                ->whereHas('lineItems'))
            ->count();
        $unresolvedCategoryCount += LineItem::query()
            ->whereNull('category_id')
            ->whereHas('receiptBreakdown', fn ($query) => $query
                ->whereBelongsTo($owner, 'owner')
                ->where('status', 'confirmed')
                ->whereHas('transaction', fn ($query) => $query->whereNull('voided_at')))
            ->count();
        $reviewQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereJsonLength('provisional_fields', '>', 0);

        $unresolvedFieldCount = (int) (clone $reviewQuery)
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(provisional_fields)), 0) AS unresolved_field_count')
            ->value('unresolved_field_count');

        $transactionModels = $reviewQuery
            ->select([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'merchant_description',
                'confirmed_at',
                'revision',
                'provisional_fields',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        $transactions = [];

        foreach ($transactionModels as $transaction) {
            $fields = [];

            foreach ($transaction->provisional_fields as $fieldName) {
                $field = ReviewableTransactionField::from($fieldName);
                $fields[] = [
                    'name' => $field->value,
                    'label' => $field->label(),
                    'value' => $field->valueFor($transaction),
                ];
            }

            $transactions[] = [
                'id' => $transaction->id,
                'revision' => $transaction->revision,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'merchant_description' => $transaction->merchant_description,
                'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
                'fields' => $fields,
            ];
        }

        $relationshipReviewQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereJsonLength('refund_relationship_review_reasons', '>', 0);

        $unresolvedRefundRelationshipCount = (int) (clone $relationshipReviewQuery)
            ->toBase()
            ->selectRaw('COALESCE(SUM(jsonb_array_length(refund_relationship_review_reasons)), 0) AS unresolved_refund_relationship_count')
            ->value('unresolved_refund_relationship_count');

        $refundsAwaitingRelationshipReview = $relationshipReviewQuery
            ->select([
                'id',
                'original_purchase_id',
                'merchant_description',
                'amount_minor',
                'currency',
                'category_id',
                'refund_relationship_review_reasons',
            ])
            ->with('category:id,name')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        $purchases = Transaction::query()
            ->whereIn(
                'id',
                $refundsAwaitingRelationshipReview
                    ->pluck('original_purchase_id')
                    ->filter(),
            )
            ->withSum([
                'linkedRefunds as linked_refund_total_minor' => fn ($query) => $query
                    ->whereNull('voided_at'),
            ], 'amount_minor')
            ->get([
                'id',
                'merchant_description',
                'amount_minor',
                'currency',
            ])
            ->keyBy('id');

        $refundRelationships = [];

        foreach ($refundsAwaitingRelationshipReview as $refund) {
            $purchase = $purchases->get($refund->original_purchase_id);

            if ($purchase === null) {
                continue;
            }

            $linkedRefundTotal = ExactInteger::from(
                (string) ($purchase->linked_refund_total_minor ?? '0'),
            );
            $purchaseAmount = ExactInteger::from($purchase->amount_minor);
            $overageMinor = $linkedRefundTotal->compare($purchaseAmount) === 1
                ? $linkedRefundTotal->subtract($purchaseAmount)->value()
                : '0';

            foreach ($refund->refund_relationship_review_reasons as $reasonValue) {
                $reason = RefundRelationshipReviewReason::from($reasonValue);
                $refundRelationships[] = [
                    'refund' => [
                        'id' => $refund->id,
                        'merchant_description' => $refund->merchant_description,
                        'amount_minor' => (string) $refund->amount_minor,
                        'currency' => $refund->currency->value,
                        'category_name' => $refund->category?->name,
                    ],
                    'purchase' => [
                        'id' => $purchase->id,
                        'merchant_description' => $purchase->merchant_description,
                        'amount_minor' => (string) $purchase->amount_minor,
                        'currency' => $purchase->currency->value,
                    ],
                    'reason' => $reason->value,
                    'reason_label' => $reason->label(),
                    'linked_refund_total_minor' => $linkedRefundTotal->value(),
                    'overage_minor' => $overageMinor,
                ];
            }
        }

        $suspectedDuplicateModels = SuspectedDuplicate::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('resolved_at')
            ->with([
                'firstTransaction' => fn ($query) => $query
                    ->with([
                        'category:id,name',
                        'spendingNotificationReferences:id,transaction_id',
                    ])
                    ->withExists([
                        'linkedRefunds',
                        'receiptBreakdowns',
                        'resolvedDuplicateRelationshipsAsSurvivor',
                    ]),
                'secondTransaction' => fn ($query) => $query
                    ->with([
                        'category:id,name',
                        'spendingNotificationReferences:id,transaction_id',
                    ])
                    ->withExists([
                        'linkedRefunds',
                        'receiptBreakdowns',
                        'resolvedDuplicateRelationshipsAsSurvivor',
                    ]),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'first_transaction_id',
                'second_transaction_id',
                'revision',
            ]);
        $suspectedDuplicates = [];

        foreach ($suspectedDuplicateModels as $suspectedDuplicate) {
            $suspectedDuplicates[] = [
                'id' => $suspectedDuplicate->id,
                'revision' => $suspectedDuplicate->revision,
                'resolution_idempotency_key' => (string) Str::uuid(),
                'first_transaction' => $this->suspectedDuplicateTransactionData(
                    $suspectedDuplicate->firstTransaction,
                ),
                'second_transaction' => $this->suspectedDuplicateTransactionData(
                    $suspectedDuplicate->secondTransaction,
                ),
            ];
        }

        return [
            'unresolved_field_count' => $unresolvedFieldCount,
            'unresolved_category_count' => $unresolvedCategoryCount,
            'unresolved_refund_relationship_count' => $unresolvedRefundRelationshipCount,
            'unresolved_suspected_duplicate_count' => $suspectedDuplicateModels->count(),
            'transactions' => $transactions,
            'refund_relationships' => $refundRelationships,
            'suspected_duplicates' => $suspectedDuplicates,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     revision: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     category_name: string|null,
     *     original_purchase_id: int|null,
     *     has_linked_refunds: bool,
     *     has_receipt_breakdown: bool,
     *     protects_resolved_duplicate: bool,
     *     source_reference_count: int,
     *     source_reference_fingerprint: string
     * }
     */
    private function suspectedDuplicateTransactionData(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'revision' => $transaction->revision,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'category_name' => $transaction->category?->name,
            'original_purchase_id' => $transaction->original_purchase_id,
            'has_linked_refunds' => (bool) $transaction->linked_refunds_exists,
            'has_receipt_breakdown' => (bool) $transaction->receipt_breakdowns_exists,
            'protects_resolved_duplicate' => (bool) $transaction->resolved_duplicate_relationships_as_survivor_exists,
            'source_reference_count' => $transaction->spendingNotificationReferences->count(),
            'source_reference_fingerprint' => SourceReferenceSetFingerprint::fromIds(
                $transaction->spendingNotificationReferences->modelKeys(),
            ),
        ];
    }
}
