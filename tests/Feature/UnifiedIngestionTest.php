<?php

use App\Actions\StatementImports\StatementImportWorkflow;
use App\DataSources\ReadRecordedCoverage;
use App\Models\GmailConnection;
use App\Models\SpendingNotificationReference;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\NotificationIngestion\SupportedSpendingNotificationRegistry;
use App\StatementImports\StatementImportValidationException;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\SyntheticPdf;

function unifiedIngestionStatementPdf(): string
{
    return SyntheticPdf::fromText((string) file_get_contents(
        __DIR__.'/../Fixtures/Statements/bcp.txt',
    ));
}

function unifiedIngestionInterbankStatementPdf(): string
{
    return SyntheticPdf::fromText((string) file_get_contents(
        __DIR__.'/../Fixtures/Statements/interbank.txt',
    ));
}

function unifiedIngestionRepeatedStatementPdf(): string
{
    $statement = (string) file_get_contents(__DIR__.'/../Fixtures/Statements/bcp.txt');
    $statement = str_replace(
        [
            "01FEB 01FEB WARDA                                  20.00\n",
            '    30.01    55.00',
            '                   124.99',
        ],
        [
            "01FEB 01FEB WARDA                                  20.00\n01FEB 01FEB WARDA                                  20.00\n",
            '    50.01    55.00',
            '                   104.99',
        ],
        $statement,
    );

    return SyntheticPdf::fromText($statement);
}

/** @return array<string, mixed> */
function unifiedIngestionConfirmation(mixed $preview): array
{
    $confirmation = $preview->confirmationData();
    $confirmation['movements'] = collect($confirmation['movements'])
        ->map(fn (array $movement): array => [
            ...$movement,
            'classification' => $movement['classification'] === 'needs_classification'
                ? 'transfer'
                : $movement['classification'],
        ])
        ->all();

    return $confirmation;
}

test('every app-owned Gmail format is fixture-backed and extracts its agreed Transaction meaning', function () {
    $registry = app(SupportedSpendingNotificationRegistry::class);

    $results = $registry->verifyFixtures();

    expect($results)->toHaveKeys([
        'bcp.debit_card_spending',
        'bcp.electronic_fee_invoice',
        'bcp.foreign_transfer_income',
        'bcp.other_bank_transfer_spending',
        'bcp.own_account_transfer',
        'bcp.third_party_transfer',
        'bcp.warda_withdrawal',
        'interbank.card_spending',
        'interbank.plin_card_spending',
        'yape.outgoing_spending',
    ])->each->toBeTrue();
});

test('a clear statement match links statement and Gmail evidence to one Transaction', function () {
    $owner = User::factory()->create();
    $recordedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    SpendingNotificationReference::factory()->for($owner, 'owner')->create([
        'transaction_id' => $recordedTransaction->id,
        'processing_outcome' => 'created',
    ]);
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );

    expect($preview->movements[0]->match)
        ->status->value->toBe('matched')
        ->transactionId->toBe($recordedTransaction->id)
        ->and($preview->confirmationData()['movements'][0])
        ->toMatchArray([
            'resolution' => 'link',
            'transaction_id' => $recordedTransaction->id,
        ]);

    $confirmation = unifiedIngestionConfirmation($preview);
    $confirmation['movements'][1]['resolution'] = 'create';
    $confirmation['movements'][1]['transaction_id'] = null;
    $statementImport = $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    );

    expect($statementImport->movements->first()->transaction_id)
        ->toBe($recordedTransaction->id)
        ->and(Transaction::query()->count())->toBe(5)
        ->and($recordedTransaction->fresh()->spendingNotificationReferences)->toHaveCount(1)
        ->and($recordedTransaction->fresh()->statementMovement)->not->toBeNull();
});

test('an Interbank statement card payment links opposite account and card movements', function () {
    $owner = User::factory()->create();
    $recordedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 9000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::CardPayment,
        'description' => 'Interbank card payment',
        'instrument_label' => 'Interbank account',
        'instrument_last_four' => '4321',
    ]);
    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'interbank.pdf',
            unifiedIngestionInterbankStatementPdf(),
        ),
    );

    expect($preview->movements[1])
        ->classification->value->toBe('card_payment')
        ->direction->value->toBe('credit')
        ->and($preview->movements[1]->match)
        ->status->value->toBe('matched')
        ->transactionId->toBe($recordedTransaction->id);
});

test('one existing Transaction claimed by repeated statement movements requires review', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-01',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
    ]);

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'repeated.pdf',
            unifiedIngestionRepeatedStatementPdf(),
        ),
    );

    expect(collect($preview->movements)->take(2)->pluck('match.status.value')->all())
        ->toBe(['ambiguous', 'ambiguous'])
        ->and(collect($preview->movements)->take(2)->pluck('match.reviewReason.value')->all())
        ->toBe(['multiple_matches', 'multiple_matches'])
        ->and(collect($preview->movements)->take(2)->pluck('match.candidates.0.id')->unique()->all())
        ->toBe([Transaction::query()->whereBelongsTo($owner, 'owner')->sole()->id]);
});

test('voided Transactions are not statement match candidates', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-01',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'voided_at' => now(),
    ]);

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            unifiedIngestionStatementPdf(),
        ),
    );

    expect($preview->movements[0]->match->status->value)->toBe('new');
});

test("another owner's Transactions are not statement match candidates", function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    Transaction::factory()->for($otherOwner, 'owner')->create([
        'occurred_on' => '2026-02-01',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            unifiedIngestionStatementPdf(),
        ),
    );

    expect($preview->movements[0]->match->status->value)->toBe('new');
});

test('Transactions already linked to a Statement Movement are not match candidates', function () {
    $owner = User::factory()->create();
    $recordedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-01',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    $statementImport = StatementImport::factory()->for($owner, 'owner')->create();
    StatementMovement::factory()
        ->for($statementImport)
        ->for($recordedTransaction)
        ->create();

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            unifiedIngestionStatementPdf(),
        ),
    );

    expect($preview->movements[0]->match->status->value)->toBe('new');
});

test('statement matching uses the three-day date proximity boundary', function (
    string $occurredOn,
    string $expectedStatus,
) {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => $occurredOn,
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            unifiedIngestionStatementPdf(),
        ),
    );

    expect($preview->movements[0]->match->status->value)->toBe($expectedStatus);
})->with([
    'three days before' => ['2026-01-29', 'matched'],
    'four days before' => ['2026-01-28', 'new'],
]);

test('an identity match with a different amount requires conflicting data review', function () {
    $owner = User::factory()->create();
    $recordedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-01',
        'amount_minor' => 2100,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            unifiedIngestionStatementPdf(),
        ),
    );
    $match = $preview->movements[0]->match;

    expect($match->status->value)->toBe('ambiguous')
        ->and($match->reviewReason?->value)->toBe('conflicting_data')
        ->and($match->candidates)->toHaveCount(1)
        ->and($match->candidates[0])->toMatchArray([
            'id' => $recordedTransaction->id,
            'amount_minor' => '2100',
            'currency' => 'PEN',
            'direction' => 'debit',
        ])
        ->and($match->candidates[0]['evidence'])->toMatchArray([
            'amount' => false,
            'currency' => true,
            'direction' => true,
            'date_proximity' => true,
            'instrument' => true,
            'description' => true,
        ]);
});

test('an identity match with conflicting financial data requires review', function (
    array $transactionAttributes,
    string $conflictingEvidence,
) {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-01',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
        ...$transactionAttributes,
    ]);

    $preview = app(StatementImportWorkflow::class)->preview(
        $owner,
        UploadedFile::fake()->createWithContent(
            'statement.pdf',
            unifiedIngestionStatementPdf(),
        ),
    );
    $match = $preview->movements[0]->match;

    expect($match->status->value)->toBe('ambiguous')
        ->and($match->reviewReason?->value)->toBe('conflicting_data')
        ->and($match->candidates)->toHaveCount(1)
        ->and($match->candidates[0]['evidence'][$conflictingEvidence])->toBeFalse();
})->with([
    'currency' => [['currency' => 'USD'], 'currency'],
    'amount and currency' => [[
        'amount_minor' => 2100,
        'currency' => 'USD',
    ], 'amount'],
    'Movement Direction' => [['direction' => MovementDirection::Credit], 'direction'],
    'Transaction Kind' => [[
        'kind' => TransactionKind::Spending,
        'transfer_purpose' => null,
    ], 'kind'],
    'Transfer Purpose' => [[
        'transfer_purpose' => TransferPurpose::Internal,
    ], 'transfer_purpose'],
]);

test('ambiguous statement movements cannot select the same Transaction twice', function () {
    $owner = User::factory()->create();

    foreach (['WARDA', 'Warda savings'] as $description) {
        Transaction::factory()->for($owner, 'owner')->create([
            'occurred_on' => '2026-02-01',
            'amount_minor' => 2000,
            'currency' => 'PEN',
            'kind' => TransactionKind::Transfer,
            'direction' => MovementDirection::Debit,
            'transfer_purpose' => TransferPurpose::Savings,
            'description' => $description,
            'instrument_label' => 'BCP Cuenta Digital',
        ]);
    }

    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionRepeatedStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);
    $transactionId = $preview->movements[0]->match->candidates[0]['id'];

    foreach ([0, 1] as $movementIndex) {
        $confirmation['movements'][$movementIndex]['resolution'] = 'link';
        $confirmation['movements'][$movementIndex]['transaction_id'] = $transactionId;
    }

    expect(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ))->toThrow(
        StatementImportValidationException::class,
        'Each Transaction can resolve only one statement movement.',
    );
});

test('a clear match rejects an incompatible owner classification', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);
    $confirmation['movements'][0]['classification'] = 'purchase';

    expect(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ))->toThrow(
        StatementImportValidationException::class,
        'The selected Transaction is recorded as a Transfer, but this statement movement is classified as Spending. Change the classification or choose a Transaction recorded as Spending.',
    );
});

test('a clear match rejects an edited amount that conflicts with the selected Transaction', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);
    $confirmation['movements'][0]['amount_minor'] = '2100';

    expect(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ))->toThrow(function (StatementImportValidationException $exception): void {
        expect($exception->errorCode)->toBe('movement_match_changed')
            ->and($exception->validationField)->toBe('movements.0.resolution');
    });
});

test('a clear automatic match rejects edited instrument identity', function (
    string $field,
    string $value,
) {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);
    $confirmation[$field] = $value;

    expect(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ))->toThrow(function (StatementImportValidationException $exception): void {
        expect($exception->errorCode)->toBe('movement_match_changed')
            ->and($exception->validationField)->toBe('movements.0.resolution');
    });
})->with([
    'instrument label' => ['instrument_label', 'BCP Savings Account'],
    'last four' => ['instrument_last_four', '5678'],
]);

test('confirmation reports a row error when an automatic match was linked after preview', function () {
    $owner = User::factory()->create();
    $recordedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);
    $statementImport = StatementImport::factory()->for($owner, 'owner')->create();
    StatementMovement::factory()
        ->for($statementImport)
        ->for($recordedTransaction)
        ->create();

    expect(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ))->toThrow(function (StatementImportValidationException $exception): void {
        expect($exception->errorCode)->toBe('invalid_movement_resolution')
            ->and($exception->validationField)->toBe('movements.0.resolution');
    });
});

test('the owner can reject a clear match and add the Statement Movement as a new Transaction', function () {
    $owner = User::factory()->create();
    $recordedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-02-02',
        'amount_minor' => 2000,
        'currency' => 'PEN',
        'kind' => TransactionKind::Transfer,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'WARDA',
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
    ]);
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);
    $confirmation['movements'][0]['resolution'] = 'create';
    $confirmation['movements'][0]['transaction_id'] = null;
    $confirmation['movements'][1]['resolution'] = 'create';
    $confirmation['movements'][1]['transaction_id'] = null;

    $statementImport = $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    );
    $statementMovement = $statementImport->movements->firstWhere('position', 1);

    expect($statementMovement->resolution->value)->toBe('created')
        ->and($statementMovement->transaction_id)->not->toBe($recordedTransaction->id)
        ->and($statementMovement->match_evidence)->toBe(['owner_confirmed_new' => true])
        ->and($recordedTransaction->fresh()->statementMovement)->toBeNull();
});

test('an ambiguous statement match requires an explicit owner resolution', function () {
    $owner = User::factory()->create();

    foreach (['WARDA', 'Warda savings'] as $description) {
        Transaction::factory()->for($owner, 'owner')->create([
            'occurred_on' => '2026-02-02',
            'amount_minor' => 2000,
            'currency' => 'PEN',
            'kind' => TransactionKind::Transfer,
            'direction' => MovementDirection::Debit,
            'transfer_purpose' => TransferPurpose::Savings,
            'description' => $description,
            'instrument_label' => 'BCP Cuenta Digital',
            'instrument_last_four' => '1234',
        ]);
    }

    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );
    $confirmation = unifiedIngestionConfirmation($preview);

    expect($preview->movements[0]->match)
        ->status->value->toBe('ambiguous')
        ->reviewReason->value->toBe('multiple_matches')
        ->and($preview->movements[0]->match->candidates)->toHaveCount(2)
        ->and($confirmation['movements'][0]['resolution'])->toBe('needs_resolution');

    expect(fn () => $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        $confirmation,
    ))->toThrow(
        StatementImportValidationException::class,
        'Choose whether to link or add this ambiguous movement.',
    );

    expect(StatementImport::query()->doesntExist())->toBeTrue();
});

test('a statement gap creates a Transaction and marks the fully resolved period verified', function () {
    $owner = User::factory()->create();
    $workflow = app(StatementImportWorkflow::class);
    $pdf = unifiedIngestionStatementPdf();
    $preview = $workflow->preview(
        $owner,
        UploadedFile::fake()->createWithContent('preview.pdf', $pdf),
    );

    expect(collect($preview->movements)->pluck('match.status.value')->unique()->all())
        ->toBe([0 => 'new', 4 => 'ambiguous'])
        ->and($preview->movements[4]->match->reviewReason?->value)->toBe('low_confidence');

    $statementImport = $workflow->confirm(
        $owner,
        UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
        unifiedIngestionConfirmation($preview),
    );

    expect($statementImport->movements)->toHaveCount(5)
        ->and($statementImport->movements->where('resolution', 'created'))->toHaveCount(5)
        ->and(Transaction::query()->count())->toBe(5);
});

test('recorded coverage distinguishes partial and fully verified periods', function () {
    $owner = User::factory()->create();
    GmailConnection::factory()->for($owner, 'owner')->create([
        'last_successful_sync_at' => '2026-08-24 14:00:00 UTC',
        'last_successful_check_at' => '2026-08-24 15:00:00 UTC',
    ]);
    $coverage = app(ReadRecordedCoverage::class);
    $dateFrom = CarbonImmutable::parse('2026-08-01');
    $dateTo = CarbonImmutable::parse('2026-08-31');

    expect($coverage->handle($owner, $dateFrom, $dateTo))
        ->status->toBe('recorded')
        ->gmail_last_checked_at->toBe('2026-08-24T15:00:00+00:00');

    StatementImport::factory()->for($owner, 'owner')->create([
        'period_start' => '2026-08-05',
        'period_end' => '2026-08-20',
    ]);

    expect($coverage->handle($owner, $dateFrom, $dateTo))
        ->status->toBe('partially_verified')
        ->verified_periods->toHaveCount(1);

    $uncoveredTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-08-12',
        'instrument_label' => 'Another account',
        'instrument_last_four' => '9876',
    ]);
    $spanningImport = StatementImport::factory()->for($owner, 'owner')->create([
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);

    expect($coverage->handle($owner, $dateFrom, $dateTo))
        ->status->toBe('partially_verified')
        ->verified_periods->toHaveCount(2);

    StatementMovement::factory()->for($spanningImport)->create([
        'transaction_id' => $uncoveredTransaction->id,
        'occurred_on' => $uncoveredTransaction->occurred_on,
        'amount_minor' => $uncoveredTransaction->amount_minor,
        'currency' => $uncoveredTransaction->currency,
        'direction' => $uncoveredTransaction->direction,
        'description' => $uncoveredTransaction->description,
    ]);

    expect($coverage->handle($owner, $dateFrom, $dateTo))
        ->status->toBe('verified')
        ->verified_periods->toHaveCount(2);
});
