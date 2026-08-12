<?php

namespace App\Http\Requests;

use App\Currency;
use App\ExactInteger;
use App\Models\Transaction;
use App\TransactionKind;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTransactionRequest extends FormRequest
{
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
            'amount_minor' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                        $fail('The :attribute field must be an integer.');
                    }
                },
                'integer',
                'min:1',
                'max:'.PHP_INT_MAX,
            ],
            'currency' => ['required', Rule::enum(Currency::class)],
            'kind' => ['required', Rule::enum(TransactionKind::class)],
            'merchant_description' => ['required', 'string', 'max:255'],
            'payment_instrument_label' => ['nullable', 'string', 'max:100'],
            'payment_instrument_last_four' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('retired_at'),
            ],
            'original_purchase_id' => [
                'nullable',
                'integer',
                Rule::exists('transactions', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->where('kind', TransactionKind::Purchase->value)
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
            $originalPurchaseId = $this->integer('original_purchase_id') ?: null;

            if ($kind === TransactionKind::Purchase && $originalPurchaseId !== null) {
                $validator->errors()->add('original_purchase_id', 'Only a Refund can link to an original purchase.');

                return;
            }

            if ($originalPurchaseId !== null) {
                $purchase = Transaction::query()->find($originalPurchaseId);

                if ($purchase?->currency !== $currency) {
                    $validator->errors()->add('original_purchase_id', 'A Refund and its original purchase must use the same currency.');
                }

                if ($purchase?->is($transaction)) {
                    $validator->errors()->add('original_purchase_id', 'A Transaction cannot link to itself.');
                }
            }

            $hasActiveLinkedRefunds = $transaction->linkedRefunds()
                ->whereNull('voided_at')
                ->exists();
            $hasLinkedRefundInAnotherCurrency = $transaction->linkedRefunds()
                ->whereNull('voided_at')
                ->where('currency', '<>', $currency->value)
                ->exists();

            if (($kind !== TransactionKind::Purchase && $hasActiveLinkedRefunds) || $hasLinkedRefundInAnotherCurrency) {
                $validator->errors()->add('kind', 'Unlink active Refunds before changing this purchase kind or currency.');
            }

            $receiptBreakdown = $transaction->receiptBreakdown()->first();

            if ($receiptBreakdown !== null) {
                $lineItemTotal = ExactInteger::from(0);

                foreach ($receiptBreakdown->lineItems()->get(['line_total_minor']) as $lineItem) {
                    $lineItemTotal = $lineItemTotal->add(ExactInteger::from($lineItem->line_total_minor));
                }

                if (
                    $lineItemTotal->compare(ExactInteger::from($this->integer('amount_minor'))) !== 0
                    && ! $this->boolean('remove_receipt_breakdown')
                ) {
                    $validator->errors()->add(
                        'amount_minor',
                        'The amount must equal the current Receipt Breakdown total unless you confirm removing its Line Items.',
                    );
                }
            }
        }];
    }
}
