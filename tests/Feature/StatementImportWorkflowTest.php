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
        ->provider->value->toBe('bcp')
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

    expect($import->movements)->toHaveCount(5)
        ->and($import->movements->whereNotNull('transaction_id'))->toHaveCount(4)
        ->and($import->movements->last()->classification->value)->toBe('income')
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
        ->provider->value->toBe('interbank')
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
        expect($exception->errorCode)->toBe($errorCode)
            ->and($exception->getMessage())->not->toContain($contents);
    }

    expect(StatementImport::query()->doesntExist())->toBeTrue();
})->with([
    'non-PDF content' => ['not a PDF', 'invalid_pdf'],
    'encrypted PDF' => [
        fn () => SyntheticPdf::fromText(bcpStatementText())."\n/Encrypt 9 0 R",
        'encrypted_pdf',
    ],
    'image-only PDF' => [fn () => SyntheticPdf::fromText('   '), 'empty_text'],
    'unknown layout' => [
        fn () => SyntheticPdf::fromText('A valid selectable-text PDF from an unknown provider'),
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
