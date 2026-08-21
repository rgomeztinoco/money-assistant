<?php

namespace App\Http\Requests\Concerns;

use App\Currency;
use App\CurrencyAmount;
use App\Rules\CurrencyAmountRule;
use Closure;

trait InteractsWithCurrencyAmountInput
{
    /** @return array<string, array<mixed>> */
    protected function currencyAmountInputRules(): array
    {
        return [
            'amount' => [
                'required_without:amount_minor',
                'prohibits:amount_minor',
                'string',
                new CurrencyAmountRule(
                    currency: is_string($this->currency) ? Currency::tryFrom($this->currency) : null,
                    mustBePositive: true,
                ),
            ],
            'amount_minor' => [
                'required_without:amount',
                'prohibits:amount',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                        $fail('The :attribute field must be an integer.');
                    }
                },
                'integer',
                'min:1',
                'max:'.PHP_INT_MAX,
            ],
        ];
    }

    public function amountMinor(): int
    {
        if ($this->filled('amount')) {
            return CurrencyAmount::minorUnits(
                $this->string('amount')->toString(),
                Currency::from($this->string('currency')->toString()),
            );
        }

        return $this->integer('amount_minor');
    }
}
