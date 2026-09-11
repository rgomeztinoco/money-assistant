<?php

namespace App\Http\Requests;

use App\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTrendsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'period' => ['nullable', Rule::in(['week', 'month', 'quarter', 'year', 'custom'])],
            'anchor' => ['nullable', 'date_format:Y-m-d'],
            'preset' => ['nullable', Rule::in(['this_month', 'last_month', 'rolling_30', 'custom'])],
            'date_from' => [Rule::requiredIf($this->input('preset') === 'custom' || $this->input('period') === 'custom'), 'nullable', 'date_format:Y-m-d'],
            'date_to' => [Rule::requiredIf($this->input('preset') === 'custom' || $this->input('period') === 'custom'), 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
