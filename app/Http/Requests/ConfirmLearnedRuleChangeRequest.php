<?php

namespace App\Http\Requests;

use App\Models\LearnedRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmLearnedRuleChangeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $rule = $this->route('learned_rule');

        return $rule === null
            || ($rule instanceof LearnedRule && $rule->user_id === $this->user()->getKey());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preview_id' => [
                'required',
                'integer',
                Rule::exists('learned_rule_change_previews', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
