<?php

namespace App\Http\Requests;

use App\Models\LineItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignLineItemCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $lineItem = $this->route('line_item');

        return $this->user() !== null
            && $lineItem instanceof LineItem
            && $lineItem->receiptBreakdown()
                ->whereHas('transaction', fn ($query) => $query
                    ->whereBelongsTo($this->user(), 'owner'))
                ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at'),
            ],
            'next_review_item' => ['nullable', 'string', 'regex:/^(transaction|line-item):[1-9][0-9]*$/'],
        ];
    }
}
