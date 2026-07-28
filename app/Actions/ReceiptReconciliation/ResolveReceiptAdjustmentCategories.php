<?php

namespace App\Actions\ReceiptReconciliation;

use App\LineItemRole;
use Illuminate\Validation\ValidationException;

final class ResolveReceiptAdjustmentCategories
{
    /**
     * @param  array<int, array{id: string|null, description: string, role: LineItemRole|string, quantity: string|null, unit_price_minor: int|null, line_total_minor: int, category_id: int|null, related_line_item_id: string|null}>  $lineItems
     * @return array<int, array{id: string|null, description: string, role: LineItemRole|string, quantity: string|null, unit_price_minor: int|null, line_total_minor: int, category_id: int|null, related_line_item_id: string|null}>
     */
    public function handle(array $lineItems): array
    {
        $lineItemsById = [];

        foreach ($lineItems as $lineItem) {
            if ($lineItem['id'] !== null) {
                $lineItemsById[$lineItem['id']] = $lineItem;
            }
        }

        foreach ($lineItems as &$lineItem) {
            $relatedLineItemId = $lineItem['related_line_item_id'];

            if ($relatedLineItemId === null) {
                continue;
            }

            $relatedLineItem = $lineItemsById[$relatedLineItemId] ?? null;
            $role = $lineItem['role'] instanceof LineItemRole
                ? $lineItem['role']
                : LineItemRole::tryFrom($lineItem['role']);
            $relatedRole = $relatedLineItem === null
                ? null
                : ($relatedLineItem['role'] instanceof LineItemRole
                    ? $relatedLineItem['role']
                    : LineItemRole::tryFrom($relatedLineItem['role']));

            if (in_array($role, [LineItemRole::PurchasedItem, LineItemRole::Unidentified], true)
                || $lineItem['id'] === $relatedLineItemId
                || $relatedRole !== LineItemRole::PurchasedItem) {
                throw ValidationException::withMessages([
                    'line_items' => 'An item-specific adjustment must reference a retained purchased Line Item in this draft.',
                ]);
            }

            if ($lineItem['category_id'] === null) {
                $lineItem['category_id'] = $relatedLineItem['category_id'];
            }
        }
        unset($lineItem);

        return $lineItems;
    }
}
