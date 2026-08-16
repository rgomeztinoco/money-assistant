<?php

namespace App\Http\Controllers;

use App\Actions\StatementImports\StatementImportWorkflow;
use App\Http\Requests\PreviewStatementImportRequest;
use App\StatementImports\StatementImportValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class StatementImportPreviewController extends Controller
{
    public function store(
        PreviewStatementImportRequest $request,
        StatementImportWorkflow $statementImportWorkflow,
    ): JsonResponse {
        $statement = $request->validated('statement');

        assert($statement instanceof UploadedFile);

        try {
            return response()->json(
                $statementImportWorkflow->preview($request->user(), $statement)->toArray(),
            );
        } catch (StatementImportValidationException $exception) {
            throw ValidationException::withMessages(['statement' => $exception->getMessage()]);
        }
    }
}
