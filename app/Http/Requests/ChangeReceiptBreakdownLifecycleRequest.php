<?php

namespace App\Http\Requests;

use App\Models\ReceiptBreakdown;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ChangeReceiptBreakdownLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $breakdown = $this->route('receipt_breakdown');

        return $breakdown instanceof ReceiptBreakdown
            && $breakdown->user_id === $this->user()->getKey();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
