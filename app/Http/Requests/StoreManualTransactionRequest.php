<?php

namespace App\Http\Requests;

use App\Currency;
use App\Http\Requests\Concerns\InteractsWithCurrencyAmountInput;
use App\IncomeSource;
use App\TransactionDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->missing('direction')) {
            $this->merge([
                'direction' => match ($this->input('kind')) {
                    TransactionKind::Refund->value, TransactionKind::Income->value => TransactionDirection::Credit->value,
                    default => TransactionDirection::Debit->value,
                },
            ]);
        }
    }

    use InteractsWithCurrencyAmountInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            ...$this->currencyAmountInputRules(),
            'currency' => ['required', Rule::enum(Currency::class)],
            'kind' => ['required', Rule::enum(TransactionKind::class)],
            'direction' => ['required', Rule::enum(TransactionDirection::class)],
            'income_source' => [
                Rule::requiredIf($this->input('kind') === TransactionKind::Income->value),
                'nullable',
                Rule::enum(IncomeSource::class),
            ],
            'transfer_purpose' => [
                Rule::requiredIf($this->input('kind') === TransactionKind::Transfer->value),
                'nullable',
                Rule::enum(TransferPurpose::class),
            ],
            'merchant_description' => ['required', 'string', 'max:255'],
            'payment_instrument_label' => ['nullable', 'string', 'max:100'],
            'payment_instrument_last_four' => ['nullable', 'regex:/^[0-9]{4}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required_without' => 'The amount field is required.',
        ];
    }
}
