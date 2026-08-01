<?php

namespace App\Http\Requests;

use App\Concerns\CategoryTargetValidationRules;
use App\Models\CategoryTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReviseCategoryTargetRequest extends FormRequest
{
    use CategoryTargetValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $target = $this->route('category_target');

        return $target instanceof CategoryTarget
            && $target->user_id === $this->user()?->getKey();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount_minor' => $this->categoryTargetAmountRules(),
            'effective_month' => $this->currentOrFutureMonthRules(),
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
