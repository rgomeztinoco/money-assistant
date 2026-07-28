<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ReadCategoryAssignmentProvenance;
use App\Actions\ReceiptReconciliation\ReadReceiptBreakdownState;
use App\Models\Transaction;
use App\Models\User;

/**
 * @phpstan-import-type CategoryAssignmentProvenanceData from ReadCategoryAssignmentProvenance
 * @phpstan-import-type ReceiptBreakdownState from ReadReceiptBreakdownState
 */
class ReadTransactionForOpenClaw
{
    public function __construct(
        private ReadCategoryAssignmentProvenance $readCategoryAssignmentProvenance,
        private ReadReceiptBreakdownState $readReceiptBreakdownState,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     revision: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     status: string,
     *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
     *     receipt_breakdown: ReceiptBreakdownState
     * }|null
     */
    public function handle(User $owner, int $transactionId): ?array
    {
        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($transactionId)
            ->with([
                'category:id,name',
                'originalPurchase:id,merchant_description',
                'currentCategoryAssignment.owner:id,name',
                'currentCategoryAssignment.linkedPurchase:id,merchant_description',
                'receiptBreakdowns.lineItems.category:id,name',
            ])
            ->first([
                'id',
                'revision',
                'occurred_on',
                'amount_minor',
                'currency',
                'kind',
                'merchant_description',
                'voided_at',
                'category_id',
                'category_assignment_provenance',
                'original_purchase_id',
            ]);

        if ($transaction === null) {
            return null;
        }

        return [
            'id' => $transaction->id,
            'revision' => $transaction->revision,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'status' => $transaction->voided_at === null ? 'active' : 'voided',
            'category' => $transaction->category === null
                ? null
                : [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                    'provenance' => $this->readCategoryAssignmentProvenance->handle($transaction, $owner),
                ],
            'receipt_breakdown' => $this->readReceiptBreakdownState->handle($transaction),
        ];
    }
}
