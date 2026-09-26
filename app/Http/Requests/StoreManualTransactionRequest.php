<?php

namespace App\Http\Requests;

use App\Currency;
use App\Http\Requests\Concerns\InteractsWithCurrencyAmountInput;
use App\IncomeSource;
use App\Models\Category;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreManualTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->missing('direction')) {
            $this->merge([
                'direction' => match ($this->input('kind')) {
                    TransactionKind::Refund->value, TransactionKind::Income->value => MovementDirection::Credit->value,
                    default => MovementDirection::Debit->value,
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
            'direction' => ['required', Rule::enum(MovementDirection::class)],
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
            'description' => ['required', 'string', 'max:255'],
            'instrument_label' => ['nullable', 'string', 'max:100'],
            'instrument_last_four' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::prohibitedIf(! in_array($this->input('kind'), [TransactionKind::Spending->value, TransactionKind::Refund->value], true)),
                Rule::exists('categories', 'id')->where('user_id', $this->user()->getKey()),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('category_id')) {
                return;
            }

            $isAssignable = Category::query()
                ->whereBelongsTo($this->user(), 'owner')
                ->whereKey($this->integer('category_id'))
                ->availableForAssignment()
                ->exists();

            if (! $isAssignable) {
                $validator->errors()->add('category_id', 'Choose an active Category owned by you.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required_without' => 'The amount field is required.',
        ];
    }
}
