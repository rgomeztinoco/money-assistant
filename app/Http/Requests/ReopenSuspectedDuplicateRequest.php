<?php

namespace App\Http\Requests;

use App\Models\SuspectedDuplicate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReopenSuspectedDuplicateRequest extends FormRequest
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
        return [
            'expected_suspected_duplicate_revision' => ['required', 'integer', 'min:1'],
            'expected_first_transaction_revision' => ['required', 'integer', 'min:1'],
            'expected_second_transaction_revision' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
