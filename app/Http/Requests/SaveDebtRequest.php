<?php

namespace App\Http\Requests;

use App\Currency;
use App\DebtDirection;
use App\Models\Debt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDebtRequest extends FormRequest
{
    /** @return array{name: string, counterparty: string, direction: string, currency: string, opening_balance_minor: int|string, opened_on: string} */
    public function debtData(): array
    {
        return ['name' => $this->string('name')->toString(), 'counterparty' => $this->string('counterparty')->toString(),
            'direction' => $this->string('direction')->toString(), 'currency' => $this->string('currency')->toString(),
            'opening_balance_minor' => $this->integer('opening_balance_minor'), 'opened_on' => $this->string('opened_on')->toString()];
    }

    public function authorize(): bool
    {
        $debt = $this->route('debt');

        return $this->user() !== null && ($debt === null || ($debt instanceof Debt && $debt->user_id === $this->user()->id));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'counterparty' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'direction' => ['required', Rule::enum(DebtDirection::class)],
            'currency' => ['required', Rule::enum(Currency::class)],
            'opening_balance_minor' => ['required', 'regex:/^\d+$/D', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'opened_on' => ['required', 'date_format:Y-m-d']];
    }
}
