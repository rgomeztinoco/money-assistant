<?php

namespace App\Http\Requests;

use App\Models\MerchantRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteMerchantRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $merchantRule = $this->route('merchant_rule');

        return $this->user() !== null
            && $merchantRule instanceof MerchantRule;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }
}
