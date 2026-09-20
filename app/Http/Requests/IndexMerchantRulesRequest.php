<?php

namespace App\Http\Requests;

use App\Currency;
use App\TransactionKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMerchantRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->getKey()),
            ],
            'status' => ['nullable', Rule::in(['all', 'enabled', 'disabled'])],
            'kind' => ['nullable', Rule::in([
                'all',
                'any',
                TransactionKind::Spending->value,
                TransactionKind::Refund->value,
            ])],
            'currency' => ['nullable', Rule::in(['all', 'any', ...array_column(Currency::cases(), 'value')])],
            'sort' => ['nullable', Rule::in(['category', 'merchant', 'kind', 'currency', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'transaction' => [
                'nullable',
                'integer',
                Rule::exists('transactions', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereIn('kind', [TransactionKind::Spending->value, TransactionKind::Refund->value]),
            ],
        ];
    }
}
