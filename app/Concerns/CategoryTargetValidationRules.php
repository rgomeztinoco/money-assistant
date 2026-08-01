<?php

namespace App\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait CategoryTargetValidationRules
{
    /** @return list<ValidationRule|Closure|string> */
    protected function categoryTargetAmountRules(): array
    {
        return [
            'required',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                    $fail('The :attribute field must be an integer.');
                }
            },
            'integer',
            'min:0',
            'max:'.PHP_INT_MAX,
        ];
    }

    /** @return list<ValidationRule|Closure|string> */
    protected function currentOrFutureMonthRules(): array
    {
        return [
            'bail',
            'required',
            'date_format:Y-m-d',
            'after_or_equal:'.CarbonImmutable::today()->startOfMonth()->toDateString(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $month = CarbonImmutable::createFromFormat('!Y-m-d', $value);

                if ($month !== null && ! $month->isStartOfMonth()) {
                    $fail('The :attribute field must be the first day of a month.');
                }
            },
        ];
    }
}
