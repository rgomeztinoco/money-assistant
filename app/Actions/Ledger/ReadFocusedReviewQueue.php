<?php

namespace App\Actions\Ledger;

use App\MerchantNormalizer;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use InvalidArgumentException;

/**
 * @phpstan-type ReviewTransactionData array{id: int, occurred_on: string, amount_minor: string, currency: string, kind: string, description: string, confirmed_at: string, category: array{id: int, name: string}|null}
 * @phpstan-type CategoryReasonData array{type: 'category', label: string}
 * @phpstan-type ReviewFieldData array{name: string, label: string, value: string}
 * @phpstan-type FieldReasonData array{type: 'field', label: string, field: ReviewFieldData}
 * @phpstan-type RefundRelationshipReasonData array{type: 'refund_relationship', name: string, label: string}
 * @phpstan-type MerchantContextData array{normalized_merchant: string|null, matching_uncategorized_count: int}
 * @phpstan-type TransactionQueueItemData array{key: string, type: 'transaction', transaction: ReviewTransactionData, reasons: list<CategoryReasonData|FieldReasonData|RefundRelationshipReasonData>, merchant_context: MerchantContextData}
 * @phpstan-type LineItemData array{id: int, line_item_id: string, description: string, quantity: string|null, unit_price_minor: string|null, line_total_minor: string}
 * @phpstan-type LineItemQueueItemData array{key: string, type: 'line_item', transaction: ReviewTransactionData, line_item: LineItemData, reasons: list<CategoryReasonData>}
 * @phpstan-type QueueItemData TransactionQueueItemData|LineItemQueueItemData
 */
class ReadFocusedReviewQueue
{
    public function __construct(
        private CountOutstandingReviews $countOutstandingReviews,
        private MerchantNormalizer $merchantNormalizer,
    ) {}

    /**
     * @return array{
     *     unresolved_count: int,
     *     item_count: int,
     *     items: list<QueueItemData>
     * }
     */
    public function handle(User $owner): array
    {
        $matchingUncategorizedCounts = $this->matchingUncategorizedCounts($owner);
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereRequiresReview()
            ->select([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'description',
                'confirmed_at',
                'provisional_fields',
                'refund_relationship_review_reasons',
                'category_id',
            ])
            ->with([
                'category:id,name',
                'receiptBreakdown:id,transaction_id',
                'receiptBreakdown.lineItems:id,line_item_id,receipt_breakdown_id,category_id,description,quantity,unit_price_minor,line_total_minor',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
        $items = [];

        foreach ($transactions as $transaction) {
            $transactionReasons = [];
            $hasReceiptLineItems = $transaction->receiptBreakdown?->lineItems->isNotEmpty() === true;

            if ($transaction->kind->supportsCategory()
                && $transaction->category_id === null
                && ! $hasReceiptLineItems) {
                $transactionReasons[] = [
                    'type' => 'category',
                    'label' => 'This Transaction needs a Category.',
                ];
            }

            foreach ($transaction->provisional_fields as $fieldName) {
                $field = ReviewableTransactionField::from($fieldName);
                $transactionReasons[] = [
                    'type' => 'field',
                    'label' => $field->label().' needs confirmation.',
                    'field' => [
                        'name' => $field->value,
                        'label' => $field->label(),
                        'value' => $field->valueFor($transaction),
                    ],
                ];
            }

            foreach ($transaction->refund_relationship_review_reasons as $reasonValue) {
                $reason = RefundRelationshipReviewReason::from($reasonValue);
                $transactionReasons[] = [
                    'type' => 'refund_relationship',
                    'name' => $reason->value,
                    'label' => $reason->label(),
                ];
            }

            if ($transactionReasons !== []) {
                $normalizedMerchant = $this->normalizedMerchant($transaction->description);
                $items[] = [
                    'key' => 'transaction:'.$transaction->id,
                    'type' => 'transaction',
                    'transaction' => $this->transactionData($transaction),
                    'reasons' => $transactionReasons,
                    'merchant_context' => [
                        'normalized_merchant' => $normalizedMerchant,
                        'matching_uncategorized_count' => $normalizedMerchant === null
                            ? 0
                            : ($matchingUncategorizedCounts[$normalizedMerchant] ?? 0),
                    ],
                ];
            }

            $lineItems = $transaction->receiptBreakdown?->lineItems;

            if ($lineItems === null) {
                continue;
            }

            foreach ($lineItems as $lineItem) {
                if ($lineItem->category_id !== null) {
                    continue;
                }

                $items[] = [
                    'key' => 'line-item:'.$lineItem->id,
                    'type' => 'line_item',
                    'transaction' => $this->transactionData($transaction),
                    'line_item' => [
                        'id' => $lineItem->id,
                        'line_item_id' => $lineItem->line_item_id,
                        'description' => $lineItem->description,
                        'quantity' => $lineItem->quantity,
                        'unit_price_minor' => $lineItem->unit_price_minor === null
                            ? null
                            : (string) $lineItem->unit_price_minor,
                        'line_total_minor' => (string) $lineItem->line_total_minor,
                    ],
                    'reasons' => [[
                        'type' => 'category',
                        'label' => 'This Line Item needs a Category.',
                    ]],
                ];
            }
        }

        return [
            'unresolved_count' => $this->countOutstandingReviews->handle($owner),
            'item_count' => count($items),
            'items' => $items,
        ];
    }

    /** @return array<string, int> */
    private function matchingUncategorizedCounts(User $owner): array
    {
        $uncategorizedTransactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereCategoryRequiresReview()
            ->get(['description']);
        $counts = [];

        foreach ($uncategorizedTransactions as $transaction) {
            $normalizedMerchant = $this->normalizedMerchant($transaction->description);

            if ($normalizedMerchant !== null) {
                $counts[$normalizedMerchant] = ($counts[$normalizedMerchant] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function normalizedMerchant(string $merchant): ?string
    {
        try {
            return $this->merchantNormalizer->normalize($merchant);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return ReviewTransactionData */
    private function transactionData(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'description' => $transaction->description,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'category' => $transaction->category === null
                ? null
                : [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                ],
        ];
    }
}
