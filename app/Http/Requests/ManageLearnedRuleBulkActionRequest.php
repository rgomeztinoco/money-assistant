<?php

namespace App\Http\Requests;

use App\Models\LearnedRuleBulkAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ManageLearnedRuleBulkActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $bulkAction = $this->route('learned_rule_bulk_action');

        return $bulkAction instanceof LearnedRuleBulkAction
            && $bulkAction->user_id === $this->user()->getKey();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
        ];
    }
}
