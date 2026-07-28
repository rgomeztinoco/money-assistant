<?php

namespace App\Http\Requests;

use App\LineItemRole;
use App\Models\ReceiptBreakdown;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class UpdateReceiptBreakdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        $breakdown = $this->route('receipt_breakdown');

        return $breakdown instanceof ReceiptBreakdown
            && $breakdown->user_id === $this->user()->getKey();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'line_items' => ['required', 'array', 'min:1', 'max:200'],
            'line_items.*' => ['required', 'array:id,description,role,quantity,unit_price_minor,line_total_minor,category_id,related_line_item_id'],
            'line_items.*.id' => ['nullable', 'uuid', 'distinct'],
            'line_items.*.description' => ['required', 'string', 'max:255'],
            'line_items.*.role' => ['sometimes', Rule::enum(LineItemRole::class)],
            'line_items.*.quantity' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/D'],
            'line_items.*.unit_price_minor' => [
                'sometimes',
                'nullable',
                'integer',
                'min:-9007199254740991',
                'max:9007199254740991',
            ],
            'line_items.*.line_total_minor' => [
                'required',
                'integer',
                'not_in:0',
                'min:-9007199254740991',
                'max:9007199254740991',
            ],
            'line_items.*.category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('retired_at'),
            ],
            'line_items.*.related_line_item_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @return list<array{id: string|null, description: string, role: string, quantity: string|null, unit_price_minor: int|null, line_total_minor: int, category_id: int|null, related_line_item_id: string|null}>
     */
    public function lineItems(): array
    {
        $lineItems = [];

        foreach ($this->array('line_items') as $lineItem) {
            if (! is_array($lineItem)
                || (! is_string($lineItem['id'] ?? null) && ($lineItem['id'] ?? null) !== null)
                || ! is_string($lineItem['description'] ?? null)
                || (! is_string($lineItem['role'] ?? null) && array_key_exists('role', $lineItem))
                || (! is_string($lineItem['quantity'] ?? null) && ($lineItem['quantity'] ?? null) !== null)
                || (! is_string($lineItem['related_line_item_id'] ?? null)
                    && ($lineItem['related_line_item_id'] ?? null) !== null)
                || ! array_key_exists('category_id', $lineItem)
                || (! is_int($lineItem['unit_price_minor'] ?? null)
                    && ! is_string($lineItem['unit_price_minor'] ?? null)
                    && ($lineItem['unit_price_minor'] ?? null) !== null)
                || (! is_int($lineItem['line_total_minor'] ?? null)
                    && ! is_string($lineItem['line_total_minor'] ?? null))
                || (! is_int($lineItem['category_id'] ?? null)
                    && ! is_string($lineItem['category_id'] ?? null)
                    && ($lineItem['category_id'] ?? null) !== null)) {
                throw new LogicException('Validated Receipt Breakdown input could not be normalized.');
            }

            $lineItems[] = [
                'id' => $lineItem['id'] ?? null,
                'description' => $lineItem['description'],
                'role' => $lineItem['role'] ?? LineItemRole::PurchasedItem->value,
                'quantity' => $lineItem['quantity'] ?? null,
                'unit_price_minor' => ($lineItem['unit_price_minor'] ?? null) === null
                    ? null
                    : (int) $lineItem['unit_price_minor'],
                'line_total_minor' => (int) $lineItem['line_total_minor'],
                'category_id' => $lineItem['category_id'] === null
                    ? null
                    : (int) $lineItem['category_id'],
                'related_line_item_id' => $lineItem['related_line_item_id'] ?? null,
            ];
        }

        return $lineItems;
    }
}
