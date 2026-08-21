<?php

use App\Actions\StatementImports\StatementImportWorkflow;
use App\Models\Category;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportPreviewMovement;
use App\StatementImports\StatementImportValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\ConcurrentStatementImportConfirmation;
use Tests\SyntheticPdf;

function bcpStatementText(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/Statements/bcp.txt');
}

function interbankStatementText(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/Statements/interbank.txt');
}

function confirmationPayload(StatementImportPreview $preview): array
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

function expectStatementImportError(Closure $callback, string $errorCode): void
{
    try {
        $callback();
    } catch (StatementImportValidationException $exception) {
        expect($exception->errorCode)->toBe($errorCode);

        return;
    }

    throw new RuntimeException("Expected Statement Import error [{$errorCode}].");
}

test('the Statement Import workflow previews a reconciled BCP statement without retaining it', function () {
    $owner = User::factory()->create();
    $statement = UploadedFile::fake()->createWithContent(
        'wrong-year.pdf',
        SyntheticPdf::fromText(bcpStatementText()),
    );

    $preview = app(StatementImportWorkflow::class)->preview($owner, $statement);

    expect($preview)
        ->financialStatementFormat->value->toBe('bcp')
        ->periodStart->toDateString()->toBe('2026-02-01')
        ->periodEnd->toDateString()->toBe('2026-02-28')
        ->instrumentLabel->toBe('BCP Cuenta Digital')
        ->movements->toHaveCount(5)
        ->movements->sequence(
            fn ($movement) => $movement
                ->direction->value->toBe('debit')
                ->classification->value->toBe('warda')
                ->amountMinor->toBe('2000'),
            fn ($movement) => $movement
                ->direction->value->toBe('credit')
                ->classification->value->toBe('warda')
                ->amountMinor->toBe('500'),
            fn ($movement) => $movement->classification->value->toBe('tax'),
            fn ($movement) => $movement->classification->value->toBe('purchase'),
            fn ($movement) => $movement->classification->value->toBe('needs_classification'),
        )
        ->reconciliation->toMatchArray([
            'opening_balance_minor' => '10000',
            'debits_minor' => '3001',
            'credits_minor' => '5500',
            'closing_balance_minor' => '12499',
        ]);

    expect(StatementImport::query()->doesntExist())->toBeTrue()
        ->and(StatementMovement::query()->doesntExist())->toBeTrue()
        ->and(Transaction::query()->doesntExist())->toBeTrue();
});

test('the Statement Import workflow atomically confirms edited BCP movements and only creates spending Transactions', function () {
    $owner = User::factory()->create();
    $savings = Category::factory()->for($owner, 'owner')->create(['name' => 'savings']);
    $taxes = Category::factory()->for($owner, 'owner')->create(['name' => 'taxes']);
    $pdf = SyntheticPdf::fromText(bcpStatementText());
    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $editedMovements = collect($preview->movements)
        ->map(fn (StatementImportPreviewMovement $movement): array => [
            'source_row_id' => $movement->sourceRowId,
            'occurred_on' => $movement->occurredOn->toDateString(),
            'description' => $movement->description,
            'amount_minor' => $movement->amountMinor,
            'currency' => $movement->currency->value,
            'classification' => $movement->classification->value === 'needs_classification'
                ? 'income'
                : $movement->classification->value,
        ])
        ->all();
    $editedMovements[0]['occurred_on'] = '2026-02-06';
    $editedMovements[0]['description'] = 'Corrected WARDA deposit';
    $editedMovements[0]['amount_minor'] = '2100';
    $editedMovements[0]['currency'] = 'USD';

    $import = app(StatementImportWorkflow::class)->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        [
            'file_hash' => $preview->fileHash,
            'instrument_label' => 'BCP Savings account',
            'instrument_last_four' => '1234',
            'warda_category_id' => $savings->id,
            'movements' => $editedMovements,
        ],
    );

    $correctedMovement = $import->movements->first();

    expect($import->movements)->toHaveCount(5)
        ->and($import->movements->whereNotNull('transaction_id'))->toHaveCount(4)
        ->and($import->movements->last()->classification->value)->toBe('income')
        ->and($correctedMovement->occurred_on->toDateString())->toBe('2026-02-06')
        ->and($correctedMovement->description)->toBe('Corrected WARDA deposit')
        ->and($correctedMovement->amount_minor)->toBe(2100)
        ->and($correctedMovement->currency->value)->toBe('USD')
        ->and($correctedMovement->transaction->occurred_on->toDateString())->toBe('2026-02-06')
        ->and($correctedMovement->transaction->merchant_description)->toBe('Corrected WARDA deposit')
        ->and($correctedMovement->transaction->amount_minor)->toBe(2100)
        ->and($correctedMovement->transaction->currency->value)->toBe('USD')
        ->and(Transaction::query()->count())->toBe(4)
        ->and(Transaction::query()->where('kind', 'purchase')->count())->toBe(3)
        ->and(Transaction::query()->where('kind', 'refund')->count())->toBe(1)
        ->and(Transaction::query()->whereBelongsTo($savings, 'category')->count())->toBe(2)
        ->and(Transaction::query()->whereBelongsTo($taxes, 'category')->count())->toBe(1)
        ->and(Transaction::query()->whereNull('category_id')->count())->toBe(1)
        ->and(Transaction::query()->where('payment_instrument_label', 'BCP Savings account')->count())->toBe(4)
        ->and(Transaction::query()->where('payment_instrument_last_four', '1234')->count())->toBe(4)
        ->and(Transaction::query()->whereNotNull('merchant_rule_id')->doesntExist())->toBeTrue();
});

test('the Statement Import workflow previews and reconciles Interbank currency columns across the closed cycle', function () {
    $owner = User::factory()->create();

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText(interbankStatementText()),
        ),
    );

    expect($preview)
        ->financialStatementFormat->value->toBe('interbank')
        ->periodStart->toDateString()->toBe('2026-01-21')
        ->periodEnd->toDateString()->toBe('2026-02-20')
        ->instrumentLabel->toBe('Interbank American Express')
        ->instrumentLastFour->toBe('1234')
        ->movements->toHaveCount(6)
        ->and($preview->movements[0])
        ->occurredOn->toDateString()->toBe('2026-01-23')
        ->direction->value->toBe('credit')
        ->classification->value->toBe('needs_classification')
        ->and($preview->movements[1])
        ->classification->value->toBe('card_payment')
        ->and($preview->movements[2])
        ->occurredOn->toDateString()->toBe('2026-01-20')
        ->classification->value->toBe('purchase')
        ->and($preview->movements[3])
        ->currency->value->toBe('USD')
        ->amountMinor->toBe('1000')
        ->and($preview->movements[4])
        ->currency->value->toBe('PEN')
        ->amountMinor->toBe('500')
        ->and($preview->movements[5])
        ->description->toBe('SEGURO DESGRAVAMEN')
        ->currency->value->toBe('PEN')
        ->and($preview->reconciliation)->toMatchArray([
            'payment_total_pen_minor' => '2700',
            'payment_total_usd_minor' => '1000',
            'consumption_pen_minor' => '2500',
            'consumption_usd_minor' => '1000',
            'other_charges_pen_minor' => '200',
            'other_charges_usd_minor' => '0',
        ]);
});

test('Interbank includes installment consumption in movements and reconciliation', function () {
    $owner = User::factory()->create();
    $statementText = str_replace(
        [
            'OTROS COBROS                    S/ US$',
            '= 27.00 10.00',
        ],
        [
            "TUS CONSUMOS EN CUOTAS\nFecha Comercio                  S/ US$\n10-Feb Installment purchase     10.00 0.00\nSUBTOTAL                        10.00 0.00\nOTROS COBROS                    S/ US$",
            '= 37.00 10.00',
        ],
        interbankStatementText(),
    );

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    );

    $installmentPurchase = collect($preview->movements)
        ->firstWhere('description', 'Installment purchase');

    expect($preview->movements)->toHaveCount(7)
        ->and($installmentPurchase)->not->toBeNull()
        ->and($installmentPurchase->classification->value)->toBe('purchase')
        ->and($preview->reconciliation)->toMatchArray([
            'consumption_pen_minor' => '3500',
            'consumption_usd_minor' => '1000',
            'payment_total_pen_minor' => '3700',
            'payment_total_usd_minor' => '1000',
        ]);
});

test('Interbank preserves a negative payment subtotal joined to its decorative rule', function (string $subtotalLine) {
    $owner = User::factory()->create();
    $statementText = str_replace(
        [
            '02-Feb PAGO TARJ WEB APP        -90.00 0.00',
            'SUBTOTAL                         0.00 0.00',
            '= 27.00 10.00',
        ],
        [
            "02-Feb PAGO TARJ WEB APP        -90.00 0.00\n03-Feb SECOND PAYMENT            -5.00 0.00",
            $subtotalLine,
            '= 22.00 10.00',
        ],
        interbankStatementText(),
    );

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    );

    expect($preview->reconciliation)->toMatchArray([
        'payments_subtotal_pen_minor' => '-500',
        'payment_total_pen_minor' => '2200',
    ]);
})->with([
    'continuous decorative rule' => 'SUBTOTAL ----------------------------5.00 0.00',
    'decorative rule split by extraction whitespace' => 'SUBTOTAL -------------- --------------5.00 0.00',
]);

test('Interbank assigns a single printed amount from its physical USD column', function () {
    $owner = User::factory()->create();
    $singleUsdRow = str_pad('06-Feb Single USD amount', 38).'5.00';
    $statementText = str_replace(
        [
            '06-Feb Single PEN amount         5.00',
            'SUBTOTAL                        25.00 10.00',
            '= 27.00 10.00',
        ],
        [
            $singleUsdRow,
            'SUBTOTAL                        20.00 15.00',
            '= 22.00 15.00',
        ],
        interbankStatementText(),
    );

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    );
    $movement = collect($preview->movements)
        ->firstWhere('description', 'Single USD amount');

    expect($movement)->not->toBeNull()
        ->and($movement->currency->value)->toBe('USD')
        ->and($preview->reconciliation['consumption_pen_minor'])->toBe('2000')
        ->and($preview->reconciliation['consumption_usd_minor'])->toBe('1500');
});

test('Interbank reconstructs consumption across pages with a repeated heading', function () {
    $statementPages = explode("\n05-Feb USD Merchant", interbankStatementText(), 2);
    $statementPages[1] = "TUS CONSUMOS\nFecha Comercio                  S/ US$\n05-Feb USD Merchant".$statementPages[1];

    $preview = app(StatementImportWorkflow::class)->preview(
        User::factory()->create(),
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromPages($statementPages),
        ),
    );

    expect($preview->movements)->toHaveCount(6)
        ->and($preview->reconciliation['consumption_pen_minor'])->toBe('2500')
        ->and($preview->reconciliation['consumption_usd_minor'])->toBe('1000');
});

test('Interbank infers movement years from a statement cycle crossing calendar years', function () {
    $statementText = str_replace(
        [
            '21/01/2026', '20/02/2026',
            '23-Ene', '02-Feb', '20-Ene', '05-Feb', '06-Feb', '20-Feb',
        ],
        [
            '21/12/2025', '20/01/2026',
            '23-Dic', '02-Ene', '20-Dic', '05-Ene', '06-Ene', '20-Ene',
        ],
        interbankStatementText(),
    );

    $preview = app(StatementImportWorkflow::class)->preview(
        User::factory()->create(),
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    );

    expect($preview->movements[0]->occurredOn->toDateString())->toBe('2025-12-23')
        ->and($preview->movements[1]->occurredOn->toDateString())->toBe('2026-01-02')
        ->and($preview->movements[2]->occurredOn->toDateString())->toBe('2025-12-20');
});

test('Interbank retains a posted movement before the cycle start using the inferred cycle year', function () {
    $preview = app(StatementImportWorkflow::class)->preview(
        User::factory()->create(),
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText(interbankStatementText()),
        ),
    );

    expect($preview->periodStart->toDateString())->toBe('2026-01-21')
        ->and($preview->movements[2]->occurredOn->toDateString())->toBe('2026-01-20');
});

test('Interbank exposes payment minimums as informational values', function () {
    $preview = app(StatementImportWorkflow::class)->preview(
        User::factory()->create(),
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText(interbankStatementText()),
        ),
    );

    expect($preview->informationalValues)->toBe([
        ['label' => 'Minimum payment', 'value' => '500', 'currency' => 'PEN'],
        ['label' => 'Minimum payment', 'value' => '100', 'currency' => 'USD'],
    ]);
});

test('only parser candidates can be confirmed as not a movement', function () {
    $owner = User::factory()->create();
    $savings = Category::factory()->for($owner, 'owner')->create(['name' => 'Savings']);
    $statementText = str_replace(
        '    30.01    55.00',
        "06FEB 06FEB INFORMACION                           0.00\n    30.01    55.00",
        bcpStatementText(),
    );
    $pdf = SyntheticPdf::fromText($statementText);
    $workflow = app(StatementImportWorkflow::class);
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $candidate = collect($preview->movements)->firstWhere('description', 'INFORMACION');

    expect($candidate)->not->toBeNull()
        ->and($candidate->classification->value)->toBe('not_a_movement')
        ->and($candidate->canBeExcluded)->toBeTrue();

    $confirmation = confirmationPayload($preview);
    $confirmation['warda_category_id'] = $savings->id;
    $import = $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    );

    expect($import->movement_count)->toBe(5)
        ->and($import->movements)->toHaveCount(5)
        ->and($import->movements->where('description', 'INFORMACION'))->toBeEmpty();

    $otherOwner = User::factory()->create();
    $ordinaryPreview = $workflow->preview(
        $otherOwner,
        UploadedFile::fake()->createWithContent(
            'ordinary-preview.pdf',
            SyntheticPdf::fromText(interbankStatementText()),
        ),
    );
    $confirmation = confirmationPayload($ordinaryPreview);
    $confirmation['movements'][0]['classification'] = 'not_a_movement';

    expectStatementImportError(fn () => $workflow->confirm(
        $otherOwner,
        UploadedFile::fake()->createWithContent(
            'ordinary-confirm.pdf',
            SyntheticPdf::fromText(interbankStatementText()),
        ),
        $confirmation,
    ), 'movement_cannot_be_excluded');
});

test('confirmation rejects an instrument label containing a full financial identifier', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText(interbankStatementText());
    $workflow = app(StatementImportWorkflow::class);
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = confirmationPayload($preview);
    $confirmation['instrument_label'] = 'Interbank 4111 1111 1111 1111';

    expectStatementImportError(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ), 'unsafe_instrument_label');

    expect(StatementImport::query()->doesntExist())->toBeTrue();
});

test('unsafe and unsupported PDF inputs fail without retaining source evidence', function (
    string $contents,
    string $errorCode,
) {
    $owner = User::factory()->create();

    try {
        app(StatementImportWorkflow::class)->preview(
            $owner,
            UploadedFile::fake()->createWithContent('statement.pdf', $contents),
        );

        $this->fail('The unsafe statement should have been rejected.');
    } catch (StatementImportValidationException $exception) {
        expect($exception->errorCode)->toBe($errorCode);

        if ($contents !== '') {
            expect($exception->getMessage())->not->toContain($contents);
        }
    }

    expect(StatementImport::query()->doesntExist())->toBeTrue();
})->with([
    'non-PDF content' => ['not a PDF', 'invalid_pdf'],
    'empty file' => ['', 'invalid_pdf_size'],
    'corrupt PDF' => ['%PDF-1.4 invalid structure', 'corrupt_pdf'],
    'oversized PDF' => [fn () => '%PDF-'.str_repeat('x', (8 * 1024 * 1024) + 1), 'invalid_pdf_size'],
    'encrypted PDF' => [
        fn () => SyntheticPdf::fromText(bcpStatementText())."\n/Encrypt 9 0 R",
        'encrypted_pdf',
    ],
    'image-only PDF' => [fn () => SyntheticPdf::fromText('   '), 'empty_text'],
    'unknown layout' => [
        fn () => SyntheticPdf::fromText('A valid selectable-text PDF with an unknown format'),
        'unsupported_format',
    ],
    'partial BCP signature' => [
        fn () => SyntheticPdf::fromText('Estado de Cuenta de Ahorros'),
        'unsupported_format',
    ],
    'partial Interbank signature' => [
        fn () => SyntheticPdf::fromText('PAGO DEL MES'),
        'unsupported_format',
    ],
]);

test('PDF extraction resource limits fail closed before producing a preview', function (
    string $configurationKey,
    int $configurationValue,
    string $errorCode,
) {
    config([$configurationKey => $configurationValue]);

    expectStatementImportError(fn () => app(StatementImportWorkflow::class)->preview(
        User::factory()->create(),
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText(interbankStatementText()),
        ),
    ), $errorCode);
})->with([
    'page count' => ['statement-imports.max_pages', 0, 'page_limit'],
    'extracted output' => ['statement-imports.max_extracted_bytes', 16, 'extraction_limit'],
]);

test('PDF extraction is terminated at the configured processing time limit', function () {
    config([
        'statement-imports.processing_timeout_seconds' => 1,
        'statement-imports.max_extracted_bytes' => 8 * 1024 * 1024,
    ]);
    $largeSelectablePdf = SyntheticPdf::fromText(str_repeat("line\n", 200000));

    expectStatementImportError(fn () => app(StatementImportWorkflow::class)->preview(
        new User,
        UploadedFile::fake()->createWithContent('statement.pdf', $largeSelectablePdf),
    ), 'processing_limit');
});

test('PDF failures do not expose or log source contents private filenames or full identifiers', function () {
    Log::spy();
    $privateFilename = 'interbank-4111111111111234-private.pdf';
    $sourceContents = '%PDF-1.4 private-statement-4111111111111234';

    try {
        app(StatementImportWorkflow::class)->preview(
            new User,
            UploadedFile::fake()->createWithContent($privateFilename, $sourceContents),
        );

        $this->fail('The corrupt statement should have been rejected.');
    } catch (StatementImportValidationException $exception) {
        expect($exception->getMessage())
            ->not->toContain($privateFilename)
            ->not->toContain($sourceContents)
            ->not->toContain('4111111111111234');
    }

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('log');
});

test('independent BCP reconciliation failures block preview and persistence', function (
    string $original,
    string $replacement,
    string $errorCode,
) {
    $owner = User::factory()->create();
    $statementText = str_replace($original, $replacement, bcpStatementText());

    expectStatementImportError(fn () => app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    ), $errorCode);

    expect(StatementImport::query()->doesntExist())->toBeTrue();
})->with([
    'Cargos row sum' => ['30.01    55.00', '31.01    55.00', 'bcp_debits_mismatch'],
    'Abonos row sum' => ['30.01    55.00', '30.01    56.00', 'bcp_credits_mismatch'],
    'balance arithmetic' => ['124.99', '125.00', 'bcp_balance_mismatch'],
]);

test('independent Interbank reconciliation failures block preview and persistence', function (
    string $original,
    string $replacement,
    string $errorCode,
) {
    $owner = User::factory()->create();
    $statementText = str_replace($original, $replacement, interbankStatementText());

    expectStatementImportError(fn () => app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    ), $errorCode);

    expect(StatementImport::query()->doesntExist())->toBeTrue();
})->with([
    'payments subtotal' => [
        'SUBTOTAL                         0.00 0.00',
        'SUBTOTAL                         1.00 0.00',
        'interbank_payments_mismatch',
    ],
    'consumption subtotal' => [
        'SUBTOTAL                        25.00 10.00',
        'SUBTOTAL                        26.00 10.00',
        'interbank_consumption_mismatch',
    ],
    'other charges subtotal' => [
        'SUBTOTAL                         2.00 0.00',
        'SUBTOTAL                         3.00 0.00',
        'interbank_other_charges_mismatch',
    ],
    'whole statement total' => [
        '= 27.00 10.00',
        '= 28.00 10.00',
        'interbank_statement_mismatch',
    ],
]);

test('missing duplicated and misplaced Interbank rows fail reconciliation', function (
    string $statementText,
    string $errorCode,
) {
    expectStatementImportError(fn () => app(StatementImportWorkflow::class)->preview(
        User::factory()->create(),
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            SyntheticPdf::fromText($statementText),
        ),
    ), $errorCode);
})->with([
    'missing row' => [
        fn () => str_replace("20-Ene Grocery                  20.00 0.00\n", '', interbankStatementText()),
        'interbank_consumption_mismatch',
    ],
    'duplicated row' => [
        fn () => str_replace(
            '20-Ene Grocery                  20.00 0.00',
            "20-Ene Grocery                  20.00 0.00\n20-Ene Grocery                  20.00 0.00",
            interbankStatementText(),
        ),
        'interbank_consumption_mismatch',
    ],
    'misplaced currency amount' => [
        fn () => str_replace(
            '20-Ene Grocery                  20.00 0.00',
            '20-Ene Grocery                   0.00 20.00',
            interbankStatementText(),
        ),
        'interbank_consumption_mismatch',
    ],
]);

test('confirmation cannot omit substitute or leave a real movement unclassified', function (
    Closure $mutate,
    string $errorCode,
) {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText(interbankStatementText());
    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = $mutate(confirmationPayload($preview));

    expectStatementImportError(fn () => app(StatementImportWorkflow::class)->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ), $errorCode);

    expect(StatementImport::query()->doesntExist())->toBeTrue()
        ->and(StatementMovement::query()->doesntExist())->toBeTrue()
        ->and(Transaction::query()->doesntExist())->toBeTrue();
})->with([
    'omitted row' => [
        function (array $confirmation): array {
            array_pop($confirmation['movements']);

            return $confirmation;
        },
        'movement_set_mismatch',
    ],
    'substituted row' => [
        function (array $confirmation): array {
            $confirmation['movements'][0]['source_row_id'] = str_repeat('a', 64);

            return $confirmation;
        },
        'movement_set_mismatch',
    ],
    'unclassified row' => [
        function (array $confirmation): array {
            $confirmation['movements'][0]['classification'] = 'needs_classification';

            return $confirmation;
        },
        'movement_needs_classification',
    ],
]);

test('exact statement replay is owner scoped and rejected atomically for the same owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $pdf = SyntheticPdf::fromText(interbankStatementText());
    $workflow = app(StatementImportWorkflow::class);
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = confirmationPayload($preview);

    $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    );

    expectStatementImportError(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('replay.pdf', $pdf),
        $confirmation,
    ), 'duplicate_statement');

    $otherPreview = $workflow->preview(
        $otherOwner,
        UploadedFile::fake()->createWithContent('other-preview.pdf', $pdf),
    );
    $workflow->confirm(
        $otherOwner,
        UploadedFile::fake()->createWithContent('other-confirm.pdf', $pdf),
        confirmationPayload($otherPreview),
    );

    expect(StatementImport::query()->count())->toBe(2)
        ->and(StatementMovement::query()->count())->toBe(12)
        ->and(Transaction::query()->count())->toBe(8);
});

test('a failure after persistence begins rolls back the complete Statement Import', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText(interbankStatementText());
    $workflow = app(StatementImportWorkflow::class);
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $movementCreations = 0;
    $eventName = 'eloquent.creating: '.StatementMovement::class;

    Event::listen($eventName, function () use (&$movementCreations): void {
        $movementCreations++;

        if ($movementCreations === 3) {
            throw new RuntimeException('Forced persistence failure.');
        }
    });

    try {
        expect(fn () => $workflow->confirm(
            $owner,
            UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
            confirmationPayload($preview),
        ))->toThrow(RuntimeException::class, 'Forced persistence failure.');
    } finally {
        Event::forget($eventName);
    }

    expect($movementCreations)->toBe(3)
        ->and(StatementImport::query()->doesntExist())->toBeTrue()
        ->and(StatementMovement::query()->doesntExist())->toBeTrue()
        ->and(Transaction::query()->doesntExist())->toBeTrue();
});

test('concurrent confirmations retain one complete import and reject the duplicate', function () {
    $connectionName = 'statement_import_concurrency';
    config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);

    $owner = new User([
        'name' => 'Concurrent Statement Import Owner',
        'email' => 'statement-import-'.str()->uuid().'@example.test',
        'password' => 'password',
    ]);
    $owner->setConnection($connectionName);
    $owner->save();

    $pdf = SyntheticPdf::fromText(interbankStatementText());
    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = confirmationPayload($preview);
    $ownerId = $owner->getKey();

    $firstConfirmation = ConcurrentStatementImportConfirmation::task($ownerId, $pdf, $confirmation);
    $secondConfirmation = ConcurrentStatementImportConfirmation::task($ownerId, $pdf, $confirmation);

    try {
        $outcomes = Concurrency::driver('process')->run(
            [$firstConfirmation, $secondConfirmation],
            timeout: 30,
        );

        expect($outcomes)->toEqualCanonicalizing(['confirmed', 'duplicate_statement'])
            ->and(StatementImport::query()->where('user_id', $ownerId)->count())->toBe(1)
            ->and(StatementMovement::query()
                ->whereHas('statementImport', fn ($query) => $query->where('user_id', $ownerId))
                ->count())->toBe(6)
            ->and(Transaction::query()->where('user_id', $ownerId)->count())->toBe(4);
    } finally {
        StatementImport::on($connectionName)->where('user_id', $ownerId)->delete();
        Transaction::on($connectionName)->where('user_id', $ownerId)->delete();
        User::on($connectionName)->find($ownerId)?->delete();
        DB::purge($connectionName);
    }
});
