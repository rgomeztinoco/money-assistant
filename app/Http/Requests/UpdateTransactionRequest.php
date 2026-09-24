<?php

namespace App\Http\Requests;

use App\Currency;
use App\ExactInteger;
use App\Http\Requests\Concerns\InteractsWithCurrencyAmountInput;
use App\IncomeSource;
use App\Models\Category;
use App\Models\Transaction;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $transaction = $this->route('transaction');

        if ($this->missing('direction') && $transaction instanceof Transaction) {
            $this->merge(['direction' => $transaction->direction->value]);
        }
    }

    use InteractsWithCurrencyAmountInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction
            && $transaction->user_id === $this->user()?->getKey();
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
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->getKey()),
            ],
            'original_spending_id' => [
                'nullable',
                'integer',
                Rule::exists('transactions', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->where('kind', TransactionKind::Spending->value)
                    ->whereNull('voided_at'),
            ],
            'remove_receipt_breakdown' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $transaction = $this->route('transaction');
            assert($transaction instanceof Transaction);
            $kind = TransactionKind::from($this->string('kind')->toString());
            $currency = Currency::from($this->string('currency')->toString());
            $originalSpendingId = $this->integer('original_spending_id') ?: null;
            $categoryId = $this->integer('category_id') ?: null;

            if ($categoryId !== null && $categoryId !== $transaction->category_id) {
                $isAssignable = Category::query()
                    ->whereBelongsTo($this->user(), 'owner')
                    ->whereKey($categoryId)
                    ->availableForAssignment()
                    ->exists();

                if (! $isAssignable) {
                    $validator->errors()->add('category_id', 'Choose an active Category owned by you.');
                }
            }

            if ($kind !== TransactionKind::Refund && $originalSpendingId !== null) {
                $validator->errors()->add('original_spending_id', 'Only a Refund can link to an original spending.');

                return;
            }

            if ($originalSpendingId !== null) {
                $spending = Transaction::query()->find($originalSpendingId);

                if ($spending?->currency !== $currency) {
                    $validator->errors()->add('original_spending_id', 'A Refund and its original spending must use the same currency.');
                }

                if ($spending?->is($transaction)) {
                    $validator->errors()->add('original_spending_id', 'A Transaction cannot link to itself.');
                }
            }

            $hasActiveLinkedRefunds = $transaction->linkedRefunds()
                ->whereNull('voided_at')
                ->exists();
            $hasLinkedRefundInAnotherCurrency = $transaction->linkedRefunds()
                ->whereNull('voided_at')
                ->where('currency', '<>', $currency->value)
                ->exists();

            if (($kind !== TransactionKind::Spending && $hasActiveLinkedRefunds) || $hasLinkedRefundInAnotherCurrency) {
                $validator->errors()->add('kind', 'This Spending has linked Refunds. Update or unlink those Refunds before changing its Kind or currency.');
            }

            $receiptBreakdown = $transaction->receiptBreakdown()->first();

            if ($receiptBreakdown !== null && $kind->supportsCategory()) {
                $lineItemTotal = ExactInteger::from(0);

                foreach ($receiptBreakdown->lineItems()->get(['line_total_minor']) as $lineItem) {
                    $lineItemTotal = $lineItemTotal->add(ExactInteger::from($lineItem->line_total_minor));
                }

                if (
                    $lineItemTotal->compare(ExactInteger::from($this->amountMinor())) !== 0
                    && ! $this->boolean('remove_receipt_breakdown')
                ) {
                    $validator->errors()->add(
                        $this->filled('amount') ? 'amount' : 'amount_minor',
                        'This amount does not match the Category split. Remove the Category split to save this amount.',
                    );
                }
            }
        }];
    }
}
