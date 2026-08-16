<?php

namespace App\Http\Requests;

use App\Currency;
use App\StatementMovementClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmStatementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf,application/x-pdf', 'max:'.config('statement-imports.max_file_kilobytes')],
            'file_hash' => ['required', 'string', 'size:64'],
            'instrument_label' => ['required', 'string', 'max:100'],
            'instrument_last_four' => ['nullable', 'regex:/^\d{4}$/'],
            'warda_category_id' => ['nullable', 'integer'],
            'movements' => ['required', 'array', 'min:1'],
            'movements.*' => ['required', 'array'],
            'movements.*.source_row_id' => ['required', 'string', 'size:64'],
            'movements.*.occurred_on' => ['required', 'date_format:Y-m-d'],
            'movements.*.description' => ['required', 'string', 'max:255'],
            'movements.*.amount_minor' => ['required', 'regex:/^\d+$/'],
            'movements.*.currency' => ['required', Rule::enum(Currency::class)],
            'movements.*.classification' => ['required', Rule::enum(StatementMovementClassification::class)],
        ];
    }
}
