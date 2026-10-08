<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SavePlanningExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['pen_per_usd' => [
            'nullable', 'string', 'max:255',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || preg_match('/^\d+(?:\.\d+)?$/D', $value) !== 1 || preg_match('/[1-9]/', $value) !== 1) {
                    $fail('Enter a positive decimal planning rate in PEN per USD.');
                }
            },
        ]];
    }
}
