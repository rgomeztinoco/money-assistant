<?php

namespace App\Http\Requests;

use App\Concerns\CategoryTargetValidationRules;
use App\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryTargetRequest extends FormRequest
{
    use CategoryTargetValidationRules;

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
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('retired_at'),
            ],
            'amount_minor' => $this->categoryTargetAmountRules(),
            'currency' => ['required', Rule::enum(Currency::class)],
            'starts_on' => $this->currentOrFutureMonthRules(),
        ];
    }
}
