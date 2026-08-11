<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkRefundToPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');

        return $this->user() !== null
            && $refund instanceof Transaction
            && $refund->user_id === $this->user()->getKey();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $refund = $this->route('refund');

        return [
            'purchase_id' => [
                'required',
                'integer',
                Rule::exists((new Transaction)->getTable(), 'id')
                    ->where('user_id', $this->user()?->getKey())
                    ->where('kind', 'purchase')
                    ->where(
                        'currency',
                        $refund instanceof Transaction
                            ? $refund->currency->value
                            : '',
                    )
                    ->whereNull('voided_at'),
            ],
        ];
    }
}
