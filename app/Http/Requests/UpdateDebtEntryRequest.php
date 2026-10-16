<?php

namespace App\Http\Requests;

use App\Models\Debt;
use App\Models\DebtEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateDebtEntryRequest extends StoreDebtEntryRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $entry = $this->route('entry');
        $debt = $this->route('debt');

        return parent::authorize() && $debt instanceof Debt && $entry instanceof DebtEntry && $entry->debt_id === $debt->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entry = $this->route('entry');
        assert($entry instanceof DebtEntry);

        return [...parent::rules(), 'kind' => ['required', Rule::in([$entry->kind->value])], 'transaction_id' => ['prohibited']];
    }
}
