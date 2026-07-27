<?php

namespace App\Http\Requests;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\TransactionKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLearnedRulePreviewRequest extends FormRequest
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
            'learned_rule_id' => [
                'nullable',
                'integer',
                Rule::exists('learned_rules', 'id')->where('user_id', $this->user()->id),
            ],
            'expected_revision' => ['nullable', 'required_with:learned_rule_id', 'integer', 'min:1'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->whereNull('retired_at')),
            ],
            'merchant_pattern' => ['required', 'string', 'max:255'],
            'match_mode' => ['required', Rule::enum(LearnedRuleMatchMode::class)],
            'transaction_kind' => ['nullable', Rule::enum(TransactionKind::class)],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'payment_instrument_label' => ['nullable', 'string', 'max:100'],
            'payment_instrument_last_four' => ['nullable', 'regex:/^[0-9]{4}$/'],
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
