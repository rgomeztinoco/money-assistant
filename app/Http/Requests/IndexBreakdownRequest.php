<?php

namespace App\Http\Requests;

use App\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBreakdownRequest extends FormRequest
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
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'period' => ['nullable', Rule::in(['week', 'month', 'quarter', 'year', 'custom'])],
            'anchor' => ['nullable', 'date_format:Y-m-d'],
            'preset' => ['nullable', Rule::in(['this_month', 'last_month', 'rolling_30', 'custom'])],
            'date_from' => [Rule::requiredIf($this->input('preset') === 'custom' || $this->input('period') === 'custom'), 'nullable', 'date_format:Y-m-d'],
            'date_to' => [Rule::requiredIf($this->input('preset') === 'custom' || $this->input('period') === 'custom'), 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string', 'regex:/^(uncategorized|[1-9][0-9]*)$/'],
            'day' => ['nullable', 'date_format:Y-m-d'],
            'focus' => ['nullable', Rule::in(['net_spending', 'income', 'savings'])],
            'merchant' => ['nullable', 'string', 'max:255'],
            'attention' => ['nullable', 'boolean'],
            'selected' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
