<?php

namespace App\Http\Requests;

use App\Currency;
use App\CurrencyAmount;
use App\Models\YearlyPayment;
use App\Rules\CurrencyAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveYearlyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('yearly_payment');

        return $this->user() !== null && ($payment === null ||
            ($payment instanceof YearlyPayment && $payment->user_id === $this->user()->getKey()));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $currency = is_string($this->input('currency')) ? Currency::tryFrom($this->input('currency')) : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', new CurrencyAmountRule($currency, mustBePositive: true)],
            'currency' => ['required', Rule::enum(Currency::class)],
            'cushion' => ['nullable', 'string', new CurrencyAmountRule($currency), 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'due_month_day' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/D', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! preg_match('/^(\d{2})-(\d{2})$/D', $value, $parts) || ! checkdate((int) $parts[1], (int) $parts[2], 2000)) {
                    $fail('Enter a valid month and day (MM-DD).');
                }
            }],
        ];
    }

    /** @return array{name: string, amount_minor: int, currency: string, cushion_minor: int, due_month_day: string|null} */
    public function paymentAttributes(): array
    {
        $data = $this->validated();
        $currency = Currency::from($data['currency']);

        return [
            'name' => $data['name'],
            'amount_minor' => CurrencyAmount::minorUnits($data['amount'], $currency),
            'currency' => $currency->value,
            'cushion_minor' => CurrencyAmount::minorUnits($data['cushion'] ?? '0', $currency),
            'due_month_day' => $data['due_month_day'] ?? null,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['due_month_day.regex' => 'Enter a valid month and day (MM-DD).', 'cushion.regex' => 'The cushion must be a nonnegative amount with at most two decimal places.'];
    }
}
