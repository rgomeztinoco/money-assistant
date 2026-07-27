<?php

namespace App\Http\Requests;

use App\Currency;
use App\TransactionKind;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualTransactionRequest extends FormRequest
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
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                        $fail('The :attribute field must be an integer.');
                    }
                },
                'integer',
                'min:1',
                'max:'.PHP_INT_MAX,
            ],
            'currency' => ['required', Rule::enum(Currency::class)],
            'kind' => ['required', Rule::enum(TransactionKind::class)],
            'merchant_description' => ['required', 'string', 'max:255'],
            'payment_instrument_label' => ['nullable', 'string', 'max:100'],
            'payment_instrument_last_four' => ['nullable', 'regex:/^[0-9]{4}$/'],
        ];
    }
}
