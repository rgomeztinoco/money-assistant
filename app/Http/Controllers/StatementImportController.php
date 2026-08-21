<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\StatementImports\ReadStatementImport;
use App\Actions\StatementImports\ReadStatementImports;
use App\Actions\StatementImports\StatementImportWorkflow;
use App\Http\Requests\ConfirmStatementImportRequest;
use App\Models\Category;
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
    public function index(Request $request, ReadStatementImports $readStatementImports): Response
    {
        return Inertia::render('statement-imports/index', [
            'statement_imports' => $readStatementImports->handle($request->user()),
        ]);
    }

    public function create(Request $request, ReadCategoryTaxonomy $readCategoryTaxonomy): Response
    {
        $owner = $request->user();
        $suggestedWardaCategoryId = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereRaw('lower(name) = lower(?)', ['Savings'])
            ->whereNull('archived_at')
            ->where(fn ($query) => $query
                ->whereNull('parent_id')
                ->orWhereHas('parent', fn ($query) => $query->whereNull('archived_at')))
            ->value('id');

        return Inertia::render('statement-imports/create', [
            'category_options' => $readCategoryTaxonomy->activeOptions($owner),
            'suggested_warda_category_id' => $suggestedWardaCategoryId,
        ]);
    }

    public function store(
        ConfirmStatementImportRequest $request,
        StatementImportWorkflow $statementImportWorkflow,
    ): RedirectResponse {
        $validated = $request->validated();
        $statement = $validated['statement'];

        if (! $statement instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'statement' => 'The statement must be an uploaded PDF.',
            ]);
        }

        try {
            $statementImport = $statementImportWorkflow->confirm(
                $request->user(),
                $statement,
                $validated,
            );
        } catch (StatementImportValidationException $exception) {
            throw ValidationException::withMessages(['statement' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Statement Import confirmed.'),
        ]);

        return redirect()->route('statement_imports.show', $statementImport);
    }

    public function show(
        Request $request,
        StatementImport $statementImport,
        ReadStatementImport $readStatementImport,
    ): Response {
        return Inertia::render('statement-imports/show', [
            'statement_import' => $readStatementImport->handle($request->user(), $statementImport),
        ]);
    }
}
