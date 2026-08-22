<?php

namespace App\Http\Requests;

use App\CurrencyAmount;
use App\Models\Transaction;
use App\Rules\CurrencyAmountRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class UpdateReceiptBreakdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction
            && $transaction->user_id === $this->user()->getKey();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'line_items' => ['required', 'array', 'min:1', 'max:200'],
            'line_items.*' => ['required', 'array:description,quantity,unit_price,unit_price_minor,line_total,line_total_minor,category_id'],
            'line_items.*.description' => ['required', 'string', 'max:255'],
            'line_items.*.quantity' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^(?=.*[1-9])\d+(?:\.\d{1,6})?$/D'],
            'line_items.*.unit_price_minor' => [
                'sometimes',
                'prohibits:line_items.*.unit_price',
                'nullable',
                'integer',
                'min:-9007199254740991',
                'max:9007199254740991',
            ],
            'line_items.*.unit_price' => [
                'sometimes',
                'prohibits:line_items.*.unit_price_minor',
                'nullable',
                'string',
                new CurrencyAmountRule(
                    currency: $this->transaction()->currency,
                    maximumAbsoluteMinorUnits: 9_007_199_254_740_991,
                ),
            ],
            'line_items.*.line_total_minor' => [
                'required_without:line_items.*.line_total',
                'prohibits:line_items.*.line_total',
                'integer',
                'not_in:0',
                'min:-9007199254740991',
                'max:9007199254740991',
            ],
            'line_items.*.line_total' => [
                'required_without:line_items.*.line_total_minor',
                'prohibits:line_items.*.line_total_minor',
                'string',
                new CurrencyAmountRule(
                    currency: $this->transaction()->currency,
                    mustBeNonZero: true,
                    maximumAbsoluteMinorUnits: 9_007_199_254_740_991,
                ),
            ],
            'line_items.*.category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at'),
            ],
        ];
    }

    /**
     * @return list<array{description: string, quantity: string|null, unit_price_minor: int|null, line_total_minor: int, category_id: int|null}>
     */
    public function lineItems(): array
    {
        $lineItems = [];

        foreach ($this->array('line_items') as $lineItem) {
            if (! is_array($lineItem)
                || ! is_string($lineItem['description'] ?? null)
                || (! is_string($lineItem['quantity'] ?? null) && ($lineItem['quantity'] ?? null) !== null)
                || (! is_string($lineItem['unit_price'] ?? null)
                    && ($lineItem['unit_price'] ?? null) !== null)
                || (! is_int($lineItem['unit_price_minor'] ?? null)
                    && ! is_string($lineItem['unit_price_minor'] ?? null)
                    && ($lineItem['unit_price_minor'] ?? null) !== null)
                || (! is_string($lineItem['line_total'] ?? null)
                    && ($lineItem['line_total'] ?? null) !== null)
                || (! is_int($lineItem['line_total_minor'] ?? null)
                    && ! is_string($lineItem['line_total_minor'] ?? null)
                    && ($lineItem['line_total_minor'] ?? null) !== null)
                || (! is_int($lineItem['category_id'] ?? null)
                    && ! is_string($lineItem['category_id'] ?? null)
                    && ($lineItem['category_id'] ?? null) !== null)) {
                throw new LogicException('Validated Receipt Breakdown input could not be normalized.');
            }

            $lineItems[] = [
                'description' => $lineItem['description'],
                'quantity' => $lineItem['quantity'] ?? null,
                'unit_price_minor' => ($lineItem['unit_price'] ?? null) !== null
                    ? CurrencyAmount::minorUnits($lineItem['unit_price'], $this->transaction()->currency)
                    : (($lineItem['unit_price_minor'] ?? null) === null
                        ? null
                        : (int) $lineItem['unit_price_minor']),
                'line_total_minor' => ($lineItem['line_total'] ?? null) !== null
                    ? CurrencyAmount::minorUnits($lineItem['line_total'], $this->transaction()->currency)
                    : (int) $lineItem['line_total_minor'],
                'category_id' => ($lineItem['category_id'] ?? null) === null
                    ? null
                    : (int) $lineItem['category_id'],
            ];
        }

        return $lineItems;
    }

    private function transaction(): Transaction
    {
        $transaction = $this->route('transaction');

        if (! $transaction instanceof Transaction) {
            throw new LogicException('A Transaction route binding is required.');
        }

        return $transaction;
    }
}
