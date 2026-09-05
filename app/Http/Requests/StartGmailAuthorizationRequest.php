<?php

namespace App\Http\Requests;

use App\Models\GmailConnection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartGmailAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'import_days' => [
                'required',
                'integer',
                'min:'.GmailConnection::MINIMUM_IMPORT_LOOKBACK_DAYS,
                'max:'.GmailConnection::MAXIMUM_IMPORT_LOOKBACK_DAYS,
            ],
        ];
    }
}
