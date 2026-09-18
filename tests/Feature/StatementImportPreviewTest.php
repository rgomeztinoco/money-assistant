<?php

use App\Currency;
use App\FinancialStatementFormat;
use App\MovementDirection;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportPreviewMovement;
use App\StatementImports\StatementImportValidationException;
use App\StatementImports\StatementMovementMatch;
use App\StatementMovementClassification;
use App\StatementMovementMatchStatus;
use App\StatementMovementReviewReason;
use Carbon\CarbonImmutable;

function statementImportPreviewForLifecycle(?StatementMovementMatch $match = null): StatementImportPreview
{
    return new StatementImportPreview(
        financialStatementFormat: FinancialStatementFormat::Bcp,
        parserVersion: 'test-v1',
        fileHash: str_repeat('a', 64),
        periodStart: CarbonImmutable::parse('2026-08-01'),
        periodEnd: CarbonImmutable::parse('2026-08-31'),
        instrumentLabel: 'BCP Cuenta Digital',
        instrumentLastFour: '1234',
        movements: [
            new StatementImportPreviewMovement(
                sourceRowId: str_repeat('b', 64),
                position: 1,
                occurredOn: CarbonImmutable::parse('2026-08-10'),
                description: 'Market',
                amountMinor: '2500',
                currency: Currency::Pen,
                direction: MovementDirection::Debit,
                classification: StatementMovementClassification::Purchase,
                contributesToSpending: true,
                canBeExcluded: false,
                sourceMetadata: [],
                match: $match,
            ),
        ],
        informationalValues: [],
        reconciliation: [],
    );
}

test('Import Preview prepares confirmation data without exposing parser metadata', function () {
    $preview = statementImportPreviewForLifecycle();

    expect($preview->confirmationData())->toBe([
        'file_hash' => str_repeat('a', 64),
        'instrument_label' => 'BCP Cuenta Digital',
        'instrument_last_four' => '1234',
        'movements' => [[
            'source_row_id' => str_repeat('b', 64),
            'occurred_on' => '2026-08-10',
            'description' => 'Market',
            'amount_minor' => '2500',
            'currency' => 'PEN',
            'classification' => 'purchase',
            'resolution' => 'create',
            'transaction_id' => null,
            'owner_confirmed_match' => false,
        ]],
    ]);
});

test('Import Preview validates and normalizes a complete confirmation', function () {
    $preview = statementImportPreviewForLifecycle();
    $confirmation = $preview->confirmationData();
    $confirmation['instrument_label'] = '  BCP   Personal  ';
    $confirmation['movements'][0]['description'] = '  Updated   market  ';

    $validated = $preview->validateConfirmation($confirmation);

    expect($validated)
        ->instrument_label->toBe('BCP Personal')
        ->instrument_last_four->toBe('1234')
        ->and($validated['movements'])
        ->toHaveCount(1)
        ->and($validated['movements'][0]['source'])
        ->toBe($preview->movements[0])
        ->and($validated['movements'][0]['description'])
        ->toBe('Updated market');
});

test('Import Preview rejects source substitution and unsafe exclusion', function () {
    $preview = statementImportPreviewForLifecycle();
    $substituted = $preview->confirmationData();
    $substituted['movements'][0]['source_row_id'] = str_repeat('c', 64);

    expect(fn () => $preview->validateConfirmation($substituted))
        ->toThrow(fn (StatementImportValidationException $exception) => expect($exception->errorCode)->toBe('movement_set_mismatch'));

    $excluded = $preview->confirmationData();
    $excluded['movements'][0]['classification'] = 'not_a_movement';

    expect(fn () => $preview->validateConfirmation($excluded))
        ->toThrow(fn (StatementImportValidationException $exception) => expect($exception->errorCode)->toBe('movement_cannot_be_excluded'));
});

test('Import Preview explains why a proposed Transaction is incompatible with the movement classification', function () {
    $preview = statementImportPreviewForLifecycle(new StatementMovementMatch(
        status: StatementMovementMatchStatus::Ambiguous,
        transactionId: null,
        candidates: [[
            'id' => 42,
            'occurred_on' => '2026-08-10',
            'amount_minor' => '1299',
            'currency' => 'PEN',
            'direction' => 'debit',
            'description' => 'Market purchase',
            'instrument_label' => null,
            'instrument_last_four' => null,
            'kind' => 'spending',
            'transfer_purpose' => null,
            'compatible_classifications' => ['purchase', 'fee', 'tax'],
            'date_difference_days' => 0,
            'evidence' => [
                'amount' => true,
                'currency' => true,
                'direction' => true,
                'date_proximity' => true,
                'instrument' => false,
                'description' => false,
                'kind' => true,
                'transfer_purpose' => true,
                'card_payment_counterpart' => false,
                'plausible' => true,
            ],
        ]],
        evidence: [],
        reviewReason: StatementMovementReviewReason::LowConfidence,
    ));
    $confirmation = $preview->confirmationData();
    $confirmation['movements'][0]['classification'] = 'transfer';
    $confirmation['movements'][0]['resolution'] = 'link';
    $confirmation['movements'][0]['transaction_id'] = 42;

    expect(fn () => $preview->validateConfirmation($confirmation))
        ->toThrow(function (StatementImportValidationException $exception): void {
            expect($exception->errorCode)->toBe('invalid_movement_match')
                ->and($exception->getMessage())->toBe(
                    'The selected Transaction is recorded as Spending, but this statement movement is classified as a Transfer between your accounts. Change the classification or choose a Transaction recorded as a Transfer.',
                );
        });
});
