<?php

namespace App\Rules;

use App\ExactInteger;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class ExactMinorAmount implements ValidationRule
{
    public function __construct(private int $maximum = PHP_INT_MAX, private bool $signed = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match($this->signed ? '/^-?[0-9]+$/D' : '/^[0-9]+$/D', $value) !== 1) {
            $fail('The :attribute field must be an exact minor-unit decimal string.');

            return;
        }

        $amount = ExactInteger::from($value);
        if ($amount->compare(ExactInteger::from(0)) === 0
            || $amount->compare(ExactInteger::from($this->maximum)) > 0
            || $amount->compare(ExactInteger::from($this->signed ? -$this->maximum : 1)) < 0) {
            $fail('The :attribute field is outside the supported nonzero minor-unit range.');
        }
    }
}
