<?php

namespace App\Http\Requests;

use App\Models\AiCategoryProposal;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmAiCategoryProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');
        $proposal = $this->route('ai_category_proposal');

        return $transaction instanceof Transaction
            && $proposal instanceof AiCategoryProposal
            && $transaction->user_id === $this->user()->getKey()
            && $proposal->user_id === $this->user()->getKey()
            && $proposal->transaction_id === $transaction->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_transaction_revision' => ['required', 'integer', 'min:1'],
            'expected_proposal_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
