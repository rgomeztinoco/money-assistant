<?php

namespace App\Http\Requests;

use App\DebtEntryKind;
use App\Http\Requests\Concerns\InteractsWithCurrencyAmountInput;
use App\Models\Debt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDebtEntryRequest extends FormRequest
{
    use InteractsWithCurrencyAmountInput;

    protected function prepareForValidation(): void
    {
        $debt = $this->route('debt');
        if ($debt instanceof Debt) {
            $this->merge(['currency' => $debt->currency->value]);
        }
    }

    public function authorize(): bool
    {
        $debt = $this->route('debt');

        return $debt instanceof Debt && $debt->user_id === $this->user()?->id;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $isAdjustment = $this->input('kind') === DebtEntryKind::Adjustment->value;
        $isLinked = $this->filled('transaction_id');

        return ['kind' => ['required', Rule::enum(DebtEntryKind::class)],
            'occurred_on' => [$isLinked ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'transaction_id' => ['nullable', Rule::prohibitedIf($isAdjustment), 'integer', Rule::exists('transactions', 'id')->where('user_id', $this->user()->id)],
            ...($isAdjustment ? ['amount_minor' => ['required', 'regex:/^-?\d+$/D', 'integer', 'not_in:0', 'min:'.(-PHP_INT_MAX), 'max:'.PHP_INT_MAX], 'amount' => ['prohibited']] : ($isLinked ? ['amount' => ['prohibited'], 'amount_minor' => ['prohibited']] : $this->currencyAmountInputRules())),
            'description' => [$isAdjustment || $isLinked ? 'nullable' : 'required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'reason' => [$isAdjustment ? 'required' : 'nullable', 'string', 'max:255', 'not_regex:/^\s*$/']];
    }
}
