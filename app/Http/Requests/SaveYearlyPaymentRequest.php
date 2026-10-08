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
            'expected_due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array{name: string, amount_minor: int, currency: string, cushion_minor: int, expected_due_on: string|null} */
    public function paymentAttributes(): array
    {
        $data = $this->validated();
        $currency = Currency::from($data['currency']);

        return [
            'name' => $data['name'],
            'amount_minor' => CurrencyAmount::minorUnits($data['amount'], $currency),
            'currency' => $currency->value,
            'cushion_minor' => CurrencyAmount::minorUnits($data['cushion'] ?? '0', $currency),
            'expected_due_on' => $data['expected_due_on'] ?? null,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['cushion.regex' => 'The cushion must be a nonnegative amount with at most two decimal places.'];
    }
}
