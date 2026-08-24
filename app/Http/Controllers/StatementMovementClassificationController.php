<?php

namespace App\Http\Controllers;

use App\Actions\StatementImports\UpdateStatementMovementClassification;
use App\Http\Requests\UpdateStatementMovementClassificationRequest;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\StatementImports\StatementImportValidationException;
use App\StatementMovementClassification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StatementMovementClassificationController extends Controller
{
    public function update(
        UpdateStatementMovementClassificationRequest $request,
        StatementImport $statementImport,
        StatementMovement $movement,
        UpdateStatementMovementClassification $updateStatementMovementClassification,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $updateStatementMovementClassification->handle(
                owner: $request->user(),
                statementImport: $statementImport,
                statementMovement: $movement,
                classification: StatementMovementClassification::from(
                    $validated['classification'],
                ),
            );
        } catch (StatementImportValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->validationField => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Statement Movement classification updated.'),
        ]);

        return to_route('statement_imports.show', $statementImport);
    }
}
