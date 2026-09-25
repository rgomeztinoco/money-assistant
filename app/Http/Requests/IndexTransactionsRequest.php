<?php

namespace App\Http\Requests;

use App\Currency;
use App\CurrencyAmount;
use App\Rules\CurrencyAmountRule;
use App\TransactionKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $currency = Currency::tryFrom((string) $this->input('currency')) ?? Currency::Pen;

        return [
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'currency' => [Rule::requiredIf($this->filled('amount_min') || $this->filled('amount_max')), 'nullable', Rule::enum(Currency::class)],
            'amount_min' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/D', new CurrencyAmountRule($currency)],
            'amount_max' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/D', new CurrencyAmountRule($currency)],
            'kinds' => ['sometimes', 'array', 'min:1'],
            'kinds.*' => ['required', Rule::enum(TransactionKind::class), 'distinct'],
            'selected' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = [
                'search', 'date_from', 'date_to', 'currency', 'amount_min',
                'amount_max', 'kinds', 'selected', 'page',
            ];

            foreach (array_diff(array_keys($this->query()), $allowed) as $field) {
                $validator->errors()->add($field, 'The '.$field.' filter is not supported.');
            }

            if ($validator->errors()->isNotEmpty() || ! $this->filled('amount_min') || ! $this->filled('amount_max')) {
                return;
            }

            $currency = Currency::tryFrom((string) $this->input('currency')) ?? Currency::Pen;

            if (CurrencyAmount::minorUnits($this->string('amount_min')->toString(), $currency)
                > CurrencyAmount::minorUnits($this->string('amount_max')->toString(), $currency)) {
                $validator->errors()->add('amount_max', 'The maximum amount must be greater than or equal to the minimum amount.');
            }
        });
    }
}
