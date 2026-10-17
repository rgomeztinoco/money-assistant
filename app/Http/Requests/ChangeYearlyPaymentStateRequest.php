<?php

namespace App\Http\Requests;

use App\Models\YearlyPayment;
use Illuminate\Foundation\Http\FormRequest;

class ChangeYearlyPaymentStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('yearly_payment');

        return $payment instanceof YearlyPayment && $payment->user_id === $this->user()->getKey();
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
