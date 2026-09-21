<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentTokenRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('personal_access_tokens')->where(fn ($query) => $query
                ->where('tokenable_type', $this->user()->getMorphClass())
                ->where('tokenable_id', $this->user()->id))],
        ];
    }
}
