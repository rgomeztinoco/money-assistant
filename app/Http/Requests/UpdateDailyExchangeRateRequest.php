<?php

namespace App\Http\Requests;

use App\Models\DailyExchangeRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyExchangeRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $rate = $this->route('daily_exchange_rate');

        return $rate instanceof DailyExchangeRate
            && $rate->user_id === $this->user()->getKey();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'pen_per_usd' => [
                'required',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D',
                'not_regex:/^0(?:\.0{1,6})?$/D',
            ],
        ];
    }
}
