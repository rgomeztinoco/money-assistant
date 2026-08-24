<?php

namespace App\Http\Requests;

use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\StatementMovementClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatementMovementClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $statementImport = $this->route('statement_import');
        $statementMovement = $this->route('movement');

        return $statementImport instanceof StatementImport
            && $statementMovement instanceof StatementMovement
            && $statementImport->user_id === $this->user()?->getKey()
            && $statementMovement->statement_import_id === $statementImport->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classification' => [
                'required',
                Rule::enum(StatementMovementClassification::class)->except([
                    StatementMovementClassification::NeedsClassification,
                    StatementMovementClassification::AlreadyRecorded,
                    StatementMovementClassification::NotAMovement,
                ]),
            ],
        ];
    }
}
