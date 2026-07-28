<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AttachReceiptProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof Transaction
            && $transaction->user_id === $this->user()->getKey();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'receipt_proposal_id' => [
                'required',
                'uuid',
                Rule::exists('receipt_proposals', 'proposal_id')
                    ->where('user_id', $this->user()->getKey()),
            ],
        ];
    }
}
