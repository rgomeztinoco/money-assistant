<?php

namespace App\Http\Requests\Concerns;

use App\Currency;
use App\CurrencyAmount;
use App\Rules\CurrencyAmountRule;

trait InteractsWithDebtAllocationInput
{
    /** @return array<string, array<mixed>> */
    protected function debtAllocationInputRules(): array
    {
        $rules = ['interest_is_new' => ['sometimes', 'boolean']];
        foreach (['principal', 'interest'] as $field) {
            $rules[$field] = ['nullable', 'prohibits:'.$field.'_minor', 'string', new CurrencyAmountRule(is_string($this->currency) ? Currency::tryFrom($this->currency) : null), 'not_regex:/^-/'];
            $rules[$field.'_minor'] = ['nullable', 'prohibits:'.$field, 'regex:/^\d+$/D', 'integer', 'min:0', 'max:'.PHP_INT_MAX];
        }

        return $rules;
    }

    public function debtAllocationMinor(string $field): ?int
    {
        if ($this->filled($field)) {
            return CurrencyAmount::minorUnits($this->string($field)->toString(), Currency::from($this->string('currency')->toString()));
        }

        return $this->filled($field.'_minor') ? $this->integer($field.'_minor') : null;
    }
}
