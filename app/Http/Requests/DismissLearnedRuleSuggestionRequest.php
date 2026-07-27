<?php

namespace App\Http\Requests;

use App\Models\LearnedRuleSuggestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DismissLearnedRuleSuggestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $suggestion = $this->route('learned_rule_suggestion');

        return $suggestion instanceof LearnedRuleSuggestion
            && $suggestion->user_id === $this->user()->getKey();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
        ];
    }
}
