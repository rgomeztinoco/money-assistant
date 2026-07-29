<?php

namespace App\Http\Requests;

use App\Models\SuspectedDuplicate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveSuspectedDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $suspectedDuplicate = $this->route('suspected_duplicate');

        return $this->user() !== null
            && $suspectedDuplicate instanceof SuspectedDuplicate
            && $suspectedDuplicate->user_id === $this->user()->getKey();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $suspectedDuplicate = $this->route('suspected_duplicate');
        $transactionIds = $suspectedDuplicate instanceof SuspectedDuplicate
            ? [
                $suspectedDuplicate->first_transaction_id,
                $suspectedDuplicate->second_transaction_id,
            ]
            : [];

        return [
            'survivor_transaction_id' => [
                'required',
                'integer',
                Rule::in($transactionIds),
            ],
            'expected_suspected_duplicate_revision' => ['required', 'integer', 'min:1'],
            'expected_first_transaction_revision' => ['required', 'integer', 'min:1'],
            'expected_second_transaction_revision' => ['required', 'integer', 'min:1'],
            'expected_first_source_reference_fingerprint' => [
                'required',
                'string',
                'regex:/^[a-f0-9]{64}$/',
            ],
            'expected_second_source_reference_fingerprint' => [
                'required',
                'string',
                'regex:/^[a-f0-9]{64}$/',
            ],
            'expected_first_receipt_breakdown_fingerprint' => [
                'required',
                'string',
                'regex:/^[a-f0-9]{64}$/',
            ],
            'expected_second_receipt_breakdown_fingerprint' => [
                'required',
                'string',
                'regex:/^[a-f0-9]{64}$/',
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
