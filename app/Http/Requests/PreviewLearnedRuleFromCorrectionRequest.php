<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewLearnedRuleFromCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction
            && $transaction->user_id === $this->user()->getKey();
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
            'merchant_pattern' => ['prohibited'],
            'merchant_key' => ['prohibited'],
            'match_mode' => ['prohibited'],
            'transaction_kind' => ['prohibited'],
            'currency' => ['prohibited'],
            'payment_instrument_label' => ['prohibited'],
            'payment_instrument_last_four' => ['prohibited'],
            'occurred_on' => ['prohibited'],
            'date' => ['prohibited'],
            'amount' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'institution_reference' => ['prohibited'],
            'regex' => ['prohibited'],
            'fuzzy' => ['prohibited'],
            'similarity' => ['prohibited'],
        ];
    }
}
