<?php

namespace App\Http\Requests;

use App\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTrendsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', Rule::enum(Currency::class)],
        ];
    }
}
