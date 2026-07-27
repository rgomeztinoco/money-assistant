<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDailyExchangeRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'applicable_on' => ['required', 'date_format:Y-m-d'],
            'pen_per_usd' => [
                'required',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D',
                'not_regex:/^0(?:\.0{1,6})?$/D',
            ],
        ];
    }
}
