<?php

namespace App\Http\Requests;

use App\Currency;
use App\TransactionKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTransactionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'kind' => ['nullable', Rule::enum(TransactionKind::class)],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->getKey()),
            ],
            'category_state' => ['nullable', Rule::in(['categorized', 'uncategorized'])],
            'review_state' => ['nullable', Rule::in(['outstanding', 'clear'])],
            'refund_relationship' => ['nullable', Rule::in(['linked', 'unlinked', 'not_applicable'])],
            'void_state' => ['nullable', Rule::in(['all', 'active', 'voided'])],
            'selected' => ['nullable', 'integer', 'min:1'],
            'inspector' => ['nullable', Rule::in(['closed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
