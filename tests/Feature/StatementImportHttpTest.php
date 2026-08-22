<?php

use App\Actions\StatementImports\StatementImportWorkflow;
use App\Models\Category;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\User;
use App\StatementImports\StatementImportPreviewMovement;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\SyntheticPdf;

function statementImportHttpPdf(): string
{
    return SyntheticPdf::fromText((string) file_get_contents(
        __DIR__.'/../Fixtures/Statements/interbank.txt',
    ));
}

test('Statement Import pages and mutations require an authenticated owner', function () {
    $this->get('/statement-imports')->assertRedirect(route('login'));
    $this->get('/statement-imports/create')->assertRedirect(route('login'));
    $this->post('/statement-import-previews')->assertRedirect(route('login'));
    $this->post('/statement-imports')->assertRedirect(route('login'));
});

test('the owner can preview a statement through the standalone HTTP endpoint', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('statement_import_previews.store'), [
            'statement' => UploadedFile::fake()->createWithContent(
                'statement.pdf',
                statementImportHttpPdf(),
            ),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('financial_statement_format', 'interbank')
        ->assertJsonPath('movements.0.contributes_to_spending', false)
        ->assertJsonPath('movements.0.can_be_excluded', false)
        ->assertJsonPath('reconciliation.payment_total_pen_minor', '2700')
        ->assertJsonCount(6, 'movements');
});

test('the preview response never exposes a complete instrument identifier', function () {
    $owner = User::factory()->create();
    $completeIdentifier = '1234 5678 9012 3456';
    $statementText = (string) file_get_contents(
        __DIR__.'/../Fixtures/Statements/interbank.txt',
    )."\nNúmero de tarjeta {$completeIdentifier}";

    $response = $this->actingAs($owner)
        ->post(route('statement_import_previews.store'), [
            'statement' => UploadedFile::fake()->createWithContent(
                'private-statement.pdf',
                SyntheticPdf::fromText($statementText),
            ),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('instrument_last_four', '1234');

    expect($response->getContent())->not->toContain($completeIdentifier)
        ->and($response->getContent())->not->toContain('private-statement.pdf');
});

test('the create page does not ask for a Spending Category for Savings Transfers', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('statement_imports.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('statement-imports/create')
            ->missing('suggested_savings_category_id')
            ->missing('category_options'),
        );
});

test('the owner can confirm a preview and inspect it while another owner cannot', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $pdf = statementImportHttpPdf();
    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $movements = collect($preview->movements)
        ->map(fn (StatementImportPreviewMovement $movement): array => [
            'source_row_id' => $movement->sourceRowId,
            'occurred_on' => $movement->occurredOn->toDateString(),
            'description' => $movement->description,
            'amount_minor' => $movement->amountMinor,
            'currency' => $movement->currency->value,
            'classification' => $movement->classification->value === 'needs_classification'
                ? 'transfer'
                : $movement->classification->value,
        ])
        ->all();

    $response = $this->actingAs($owner)
        ->post(route('statement_imports.store'), [
            'statement' => UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
            'file_hash' => $preview->fileHash,
            'instrument_label' => $preview->instrumentLabel,
            'instrument_last_four' => $preview->instrumentLastFour,
            'movements' => $movements,
        ]);

    $import = StatementImport::query()->sole();
    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('statement_imports.show', $import));

    $this->get(route('statement_imports.show', $import))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statement-imports/show')
            ->where('statement_import.id', $import->id)
            ->has('statement_import.movements', 6)
            ->has('statement_import.summary')
            ->where('statement_import.reconciliation.payment_total_pen_minor', '2700'),
        );

    $this->actingAs($otherOwner)
        ->get(route('statement_imports.show', $import))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('statement_imports.show', PHP_INT_MAX))
        ->assertNotFound();

    $this->actingAs($owner)
        ->put(route('statement_imports.show', $import))
        ->assertMethodNotAllowed();
    $this->delete(route('statement_imports.show', $import))
        ->assertMethodNotAllowed();

    expect(StatementMovement::query()->where('classification', 'transfer')->count())->toBe(1);
});

test('semantic confirmation failures identify the affected preview row', function () {
    $owner = User::factory()->create();
    $pdf = statementImportHttpPdf();
    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $movements = collect($preview->movements)
        ->map(fn (StatementImportPreviewMovement $movement): array => [
            'source_row_id' => $movement->sourceRowId,
            'occurred_on' => $movement->occurredOn->toDateString(),
            'description' => $movement->description,
            'amount_minor' => $movement->amountMinor,
            'currency' => $movement->currency->value,
            'classification' => $movement->classification->value,
        ])
        ->all();

    $this->actingAs($owner)
        ->from(route('statement_imports.create'))
        ->post(route('statement_imports.store'), [
            'statement' => UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
            'file_hash' => $preview->fileHash,
            'instrument_label' => $preview->instrumentLabel,
            'instrument_last_four' => $preview->instrumentLastFour,
            'movements' => $movements,
        ])
        ->assertRedirect(route('statement_imports.create'))
        ->assertSessionHasErrors([
            'movements.0.classification' => 'Classify every real movement before confirming the import.',
        ]);

    expect(StatementImport::query()->doesntExist())->toBeTrue()
        ->and(StatementMovement::query()->doesntExist())->toBeTrue();
});

test('the Statement Import index is owner scoped and exposes safe metadata', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    StatementImport::factory()->for($owner, 'owner')->create([
        'instrument_label' => 'Safe account',
        'movement_count' => 3,
    ]);
    StatementImport::factory()->for($otherOwner, 'owner')->create();

    $response = $this->actingAs($owner)
        ->get(route('statement_imports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('statement-imports/index')
            ->has('statement_imports.data', 1)
            ->where('statement_imports.data.0.instrument_label', 'Safe account')
            ->has('statement_imports.data.0.totals')
            ->missing('statement_imports.data.0.file_hash'),
        );

    expect(array_keys($response->inertiaProps('statement_imports.data.0')))->toEqualCanonicalizing([
        'id',
        'financial_statement_format',
        'period_start',
        'period_end',
        'instrument_label',
        'instrument_last_four',
        'movement_count',
        'confirmed_at',
        'totals',
    ]);
});

test('BCP and Interbank imports coexist in safe history with complete movement summaries', function () {
    $owner = User::factory()->create();
    $savings = Category::factory()->for($owner, 'owner')->create(['name' => 'Savings']);
    $workflow = app(StatementImportWorkflow::class);

    foreach ([
        [statementImportHttpPdf(), null],
        [SyntheticPdf::fromText((string) file_get_contents(__DIR__.'/../Fixtures/Statements/bcp.txt')), $savings->id],
    ] as [$pdf, $savingsCategoryId]) {
        $preview = $workflow->preview(
            $owner,
            UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
        );
        $movements = collect($preview->movements)
            ->map(fn (StatementImportPreviewMovement $movement): array => [
                'source_row_id' => $movement->sourceRowId,
                'occurred_on' => $movement->occurredOn->toDateString(),
                'description' => $movement->description,
                'amount_minor' => $movement->amountMinor,
                'currency' => $movement->currency->value,
                'classification' => $movement->classification->value === 'needs_classification'
                    ? 'transfer'
                    : $movement->classification->value,
            ])
            ->all();
        $confirmation = [
            'file_hash' => $preview->fileHash,
            'instrument_label' => $preview->instrumentLabel,
            'instrument_last_four' => $preview->instrumentLastFour,
            'movements' => $movements,
        ];

        if ($savingsCategoryId !== null) {
            $confirmation['savings_category_id'] = $savingsCategoryId;
        }

        $workflow->confirm(
            $owner,
            UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
            $confirmation,
        );
    }

    $indexResponse = $this->actingAs($owner)->get(route('statement_imports.index'));

    expect($indexResponse->inertiaProps('statement_imports.data'))->toHaveCount(2)
        ->and(collect($indexResponse->inertiaProps('statement_imports.data'))->pluck('financial_statement_format')->all())
        ->toBe(['bcp', 'interbank']);

    $bcpImport = StatementImport::query()
        ->whereBelongsTo($owner, 'owner')
        ->where('financial_statement_format', 'bcp')
        ->sole();
    $detailResponse = $this->get(route('statement_imports.show', $bcpImport))
        ->assertInertia(fn (Assert $page) => $page
            ->component('statement-imports/show')
            ->where('statement_import.movements.0.classification', 'savings')
            ->where('statement_import.movements.0.direction', 'debit')
            ->where('statement_import.movements.0.transaction.kind', 'transfer')
            ->where('statement_import.movements.0.transaction.category', null)
            ->where('statement_import.movements.1.classification', 'savings')
            ->where('statement_import.movements.1.direction', 'credit')
            ->where('statement_import.movements.1.transaction.kind', 'transfer')
            ->where('statement_import.summary.PEN.savings_deposits_minor', '2000')
            ->where('statement_import.summary.PEN.savings_withdrawals_minor', '500')
            ->where('statement_import.summary.PEN.net_savings_minor', '1500')
            ->missing('statement_import.file_hash')
            ->missing('statement_import.movements.0.source_row_id')
            ->missing('statement_import.movements.0.source_metadata'),
        );
    $movement = $detailResponse->inertiaProps('statement_import.movements.0');

    expect(array_keys($movement))->toEqualCanonicalizing([
        'id',
        'position',
        'occurred_on',
        'amount_minor',
        'currency',
        'direction',
        'classification',
        'description',
        'transaction',
    ]);
});
