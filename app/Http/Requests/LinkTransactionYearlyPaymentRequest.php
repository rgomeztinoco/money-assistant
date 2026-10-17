<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use App\Models\YearlyPayment;
use App\TransactionKind;
use Illuminate\Validation\Rule;

class LinkTransactionYearlyPaymentRequest extends SaveYearlyPaymentRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $this->user() !== null && $transaction instanceof Transaction
            && $transaction->user_id === $this->user()->getKey()
            && $transaction->kind === TransactionKind::Spending
            && $transaction->voided_at === null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        if ($this->filled('yearly_payment_id')) {
            return ['yearly_payment_id' => ['required', 'integer', Rule::exists(YearlyPayment::class, 'id')->where('user_id', $this->user()->getKey())]];
        }

        return parent::rules();
    }
}
