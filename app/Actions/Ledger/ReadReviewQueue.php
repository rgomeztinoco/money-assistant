<?php

namespace App\Actions\Ledger;

use App\ExactInteger;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;

class ReadReviewQueue
{
    public function __construct(
        private CountOutstandingReviews $countOutstandingReviews,
    ) {}

    /**
     * @return array{
     *     unresolved_field_count: int,
     *     unresolved_category_count: int,
     *     unresolved_refund_relationship_count: int,
     *     transactions: list<array{
     *         id: int,
     *         occurred_on: string,
     *         amount_minor: string,
     *         currency: string,
     *         kind: string,
     *         description: string,
     *         confirmed_at: string,
     *         fields: list<array{name: string, label: string, value: string}>
     *     }>,
     *     refund_relationships: list<array{
     *         refund: array{id: int, description: string, amount_minor: string, currency: string, category_name: string|null},
     *         spending: array{id: int, description: string, amount_minor: string, currency: string},
     *         reason: string,
     *         reason_label: string,
     *         linked_refund_total_minor: string,
     *         overage_minor: string
     *     }>
     * }
     */
    public function handle(User $owner): array
    {
        $counts = $this->countOutstandingReviews->breakdown($owner);
        $reviewQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereJsonLength('provisional_fields', '>', 0);

        $transactionModels = $reviewQuery
            ->select([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'description',
                'confirmed_at',
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
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'description' => $transaction->description,
                'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
                'fields' => $fields,
            ];
        }

        $relationshipReviewQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereJsonLength('refund_relationship_review_reasons', '>', 0);
        $refundsAwaitingRelationshipReview = $relationshipReviewQuery
            ->select([
                'id',
                'original_spending_id',
                'description',
                'amount_minor',
                'currency',
                'category_id',
                'refund_relationship_review_reasons',
            ])
            ->with('category:id,name')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
        $spendings = Transaction::query()
            ->whereIn('id', $refundsAwaitingRelationshipReview->pluck('original_spending_id')->filter())
            ->withSum([
                'linkedRefunds as linked_refund_total_minor' => fn ($query) => $query
                    ->whereNull('voided_at'),
            ], 'amount_minor')
            ->get(['id', 'description', 'amount_minor', 'currency'])
            ->keyBy('id');
        $refundRelationships = [];

        foreach ($refundsAwaitingRelationshipReview as $refund) {
            $spending = $spendings->get($refund->original_spending_id);

            if ($spending === null) {
                continue;
            }

            $linkedRefundTotal = ExactInteger::from((string) ($spending->linked_refund_total_minor ?? '0'));
            $spendingAmount = ExactInteger::from($spending->amount_minor);
            $overageMinor = $linkedRefundTotal->compare($spendingAmount) === 1
                ? $linkedRefundTotal->subtract($spendingAmount)->value()
                : '0';

            foreach ($refund->refund_relationship_review_reasons as $reasonValue) {
                $reason = RefundRelationshipReviewReason::from($reasonValue);
                $refundRelationships[] = [
                    'refund' => [
                        'id' => $refund->id,
                        'description' => $refund->description,
                        'amount_minor' => (string) $refund->amount_minor,
                        'currency' => $refund->currency->value,
                        'category_name' => $refund->category?->name,
                    ],
                    'spending' => [
                        'id' => $spending->id,
                        'description' => $spending->description,
                        'amount_minor' => (string) $spending->amount_minor,
                        'currency' => $spending->currency->value,
                    ],
                    'reason' => $reason->value,
                    'reason_label' => $reason->label(),
                    'linked_refund_total_minor' => $linkedRefundTotal->value(),
                    'overage_minor' => $overageMinor,
                ];
            }
        }

        return [
            'unresolved_field_count' => $counts['fields'],
            'unresolved_category_count' => $counts['categories'],
            'unresolved_refund_relationship_count' => $counts['refund_relationships'],
            'transactions' => $transactions,
            'refund_relationships' => $refundRelationships,
        ];
    }
}
