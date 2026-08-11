<?php

namespace App\Actions\ReceiptReconciliation;

use App\ExactInteger;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;

/**
 * @phpstan-type ReceiptLineItemData array{id: string, description: string, quantity: string|null, unit_price_minor: string|null, line_total_minor: string, category: array{id: int, name: string}|null}
 * @phpstan-type ReceiptBreakdownData array{id: int, total_minor: string, line_items: list<ReceiptLineItemData>}
 */
final class ReadReceiptBreakdownState
{
    /** @return ReceiptBreakdownData|null */
    public function handle(Transaction $transaction): ?array
    {
        $transaction->loadMissing('receiptBreakdown.lineItems.category:id,name');

        return $this->breakdownData($transaction->receiptBreakdown);
    }

    /** @return ReceiptBreakdownData|null */
    private function breakdownData(?ReceiptBreakdown $breakdown): ?array
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
                'quantity' => $lineItem->quantity,
                'unit_price_minor' => $lineItem->unit_price_minor === null
                    ? null
                    : (string) $lineItem->unit_price_minor,
                'line_total_minor' => (string) $lineItem->line_total_minor,
                'category' => $lineItem->category === null
                    ? null
                    : ['id' => $lineItem->category->id, 'name' => $lineItem->category->name],
            ];
        }

        return [
            'id' => $breakdown->id,
            'total_minor' => $total->value(),
            'line_items' => $lineItems,
        ];
    }
}
