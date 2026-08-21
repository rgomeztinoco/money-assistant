<?php

use App\Actions\StatementImports\StatementImportWorkflow;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportPreviewMovement;
use Illuminate\Http\UploadedFile;
use Tests\SyntheticPdf;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner discovers Statement Imports selects a PDF and revisits a confirmed import', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/interbank.txt'),
    ));
    $workflow = app(StatementImportWorkflow::class);
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        browserStatementConfirmation($preview),
    );

    $page = visit(route('transactions.index'));

    $page
        ->click('Import statement')
        ->assertPathIs('/statement-imports/create')
        ->assertSee('Preview the PDF');
    selectPdfInBrowser($page, '#preview-statement', $pdf);
    $page
        ->assertScript("document.querySelector('#preview-statement').files.length === 1")
        ->click('Statement Imports')
        ->assertPathIs('/statement-imports')
        ->assertSee('Interbank American Express')
        ->assertSee('payment total pen')
        ->click('interbank')
        ->assertSee('Statement Movements')
        ->assertSee('Source reconciliation')
        ->assertSee('payment total usd')
        ->assertSee('Mercado Pago')
        ->assertSee('Already recorded')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(StatementImport::query()->count())->toBe(1);
});

function browserStatementConfirmation(StatementImportPreview $preview): array
{
    return [
        'file_hash' => $preview->fileHash,
        'instrument_label' => $preview->instrumentLabel,
        'instrument_last_four' => $preview->instrumentLastFour,
        'movements' => collect($preview->movements)
            ->map(fn (StatementImportPreviewMovement $movement): array => [
                'source_row_id' => $movement->sourceRowId,
                'occurred_on' => $movement->occurredOn->toDateString(),
                'description' => $movement->description,
                'amount_minor' => $movement->amountMinor,
                'currency' => $movement->currency->value,
                'classification' => $movement->classification->value === 'needs_classification'
                    ? 'already_recorded'
                    : $movement->classification->value,
            ])
            ->all(),
    ];
}

function selectPdfInBrowser(mixed $page, string $selector, string $pdf): void
{
    $base64 = base64_encode($pdf);
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    $page->script(
        <<<JS
        (() => {
            const input = document.querySelector({$selector});
            const binary = atob('{$base64}');
            const bytes = Uint8Array.from(binary, character => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'statement.pdf', { type: 'application/pdf' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })()
        JS,
    );
}
