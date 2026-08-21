<?php

namespace App\Rules;

use App\Currency;
use App\CurrencyAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;

final class CurrencyAmountRule implements ValidationRule
{
    public function __construct(
        private ?Currency $currency,
        private bool $mustBePositive = false,
        private bool $mustBeNonZero = false,
        private int $maximumAbsoluteMinorUnits = PHP_INT_MAX,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->currency === null) {
            return;
        }

        try {
            $minorUnits = CurrencyAmount::minorUnits($value, $this->currency);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());

            return;
        }

        if (abs($minorUnits) > $this->maximumAbsoluteMinorUnits) {
            $fail('The amount is too large.');
        }

        if ($this->mustBePositive && $minorUnits < 1) {
            $fail('The amount must be greater than zero.');
        }

        if ($this->mustBeNonZero && $minorUnits === 0) {
            $fail('The Line Item total cannot be zero.');
        }
    }
}
