<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\StatementImports\ReadStatementImport;
use App\Actions\StatementImports\ReadStatementImports;
use App\Actions\StatementImports\StatementImportWorkflow;
use App\Http\Requests\ConfirmStatementImportRequest;
use App\Models\StatementImport;
use App\StatementImports\StatementImportValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StatementImportController extends Controller
{
    public function __construct(
        private ReadStatementImports $readStatementImports,
        private ReadStatementImport $readStatementImport,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
        private StatementImportWorkflow $statementImportWorkflow,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('statement-imports/index', [
            'statement_imports' => $this->readStatementImports->handle($request->user()),
        ]);
    }

    public function create(Request $request): Response
    {
        $owner = $request->user();

        return Inertia::render('statement-imports/create', [
            'category_options' => $this->readCategoryTaxonomy->activeOptions($owner),
            'suggested_savings_category_id' => $this->readCategoryTaxonomy->activeCategoryIdNamed($owner, 'Savings'),
        ]);
    }

    public function store(ConfirmStatementImportRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $statement = $validated['statement'];

        if (! $statement instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'statement' => 'The statement must be an uploaded PDF.',
            ]);
        }

        try {
            $statementImport = $this->statementImportWorkflow->confirm(
                $request->user(),
                $statement,
                $validated,
            );
        } catch (StatementImportValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->validationField => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Statement Import confirmed.'),
        ]);

        return to_route('statement_imports.show', $statementImport);
    }

    public function show(
        Request $request,
        StatementImport $statementImport,
    ): Response {
        return Inertia::render('statement-imports/show', [
            'statement_import' => $this->readStatementImport->handle($request->user(), $statementImport),
        ]);
    }
}
