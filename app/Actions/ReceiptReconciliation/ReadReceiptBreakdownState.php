<?php

namespace App\Actions\ReceiptReconciliation;

use App\ExactInteger;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;

/**
 * @phpstan-type ReceiptLineItemData array{id: string, description: string, role: string, line_total_minor: string, category: array{id: int, name: string}|null, requires_review: bool}
 * @phpstan-type ReceiptBreakdownData array{id: int, revision: int, status: string, total_minor: string, delta_minor: string, confirmed_at: string|null, line_items: list<ReceiptLineItemData>}
 * @phpstan-type ReceiptBreakdownState array{draft: ReceiptBreakdownData|null, confirmed: ReceiptBreakdownData|null}
 */
final class ReadReceiptBreakdownState
{
    /** @return ReceiptBreakdownState */
    public function handle(Transaction $transaction): array
    {
        $transaction->loadMissing('receiptBreakdowns.lineItems.category:id,name');
        $receiptBreakdowns = $transaction->receiptBreakdowns->keyBy('status');

        return [
            'draft' => $this->breakdownData($receiptBreakdowns->get('draft'), $transaction),
            'confirmed' => $this->breakdownData($receiptBreakdowns->get('confirmed'), $transaction),
        ];
    }

    /** @return ReceiptBreakdownData|null */
    private function breakdownData(?ReceiptBreakdown $breakdown, Transaction $transaction): ?array
    {
        if ($breakdown === null) {
            return null;
        }

        $total = ExactInteger::from(0);
        $lineItems = [];

        foreach ($breakdown->lineItems as $lineItem) {
            $total = $total->add(ExactInteger::from($lineItem->line_total_minor));
            $lineItems[] = [
                'id' => $lineItem->line_item_id,
                'description' => $lineItem->description,
                'role' => $lineItem->role,
                'line_total_minor' => (string) $lineItem->line_total_minor,
                'category' => $lineItem->category === null
                    ? null
                    : ['id' => $lineItem->category->id, 'name' => $lineItem->category->name],
                'requires_review' => $lineItem->requires_review,
            ];
        }

        return [
            'id' => $breakdown->id,
            'revision' => $breakdown->revision,
            'status' => $breakdown->status,
            'total_minor' => $total->value(),
            'delta_minor' => ExactInteger::from($transaction->amount_minor)->subtract($total)->value(),
            'confirmed_at' => $breakdown->confirmed_at?->toIso8601String(),
            'line_items' => $lineItems,
        ];
    }
}
