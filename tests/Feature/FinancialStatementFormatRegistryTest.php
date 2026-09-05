<?php

use App\Contracts\StatementImports\FinancialStatementFormatAdapter;
use App\FinancialStatementFormat;
use App\StatementImports\FinancialStatementFormatRegistry;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportValidationException;
use Carbon\CarbonImmutable;

function financialStatementFormatAdapter(
    bool $matches,
    StatementImportPreview $preview,
): FinancialStatementFormatAdapter {
    return new class($matches, $preview) implements FinancialStatementFormatAdapter
    {
        public function __construct(
            private bool $matches,
            private StatementImportPreview $statementImportPreview,
        ) {}

        public function matches(string $statementText): bool
        {
            unset($statementText);

            return $this->matches;
        }

        public function preview(string $statementText, string $fileHash): StatementImportPreview
        {
            unset($statementText, $fileHash);

            return $this->statementImportPreview;
        }
    };
}

function financialStatementFormatPreview(): StatementImportPreview
{
    return new StatementImportPreview(
        financialStatementFormat: FinancialStatementFormat::Bcp,
        parserVersion: 'test-v1',
        fileHash: str_repeat('a', 64),
        periodStart: CarbonImmutable::parse('2026-08-01'),
        periodEnd: CarbonImmutable::parse('2026-08-31'),
        instrumentLabel: 'Test account',
        instrumentLastFour: null,
        movements: [],
        informationalValues: [],
        reconciliation: [],
    );
}

function expectFinancialStatementFormatRegistryError(Closure $callback, string $errorCode): void
{
    try {
        $callback();
    } catch (StatementImportValidationException $exception) {
        expect($exception->errorCode)->toBe($errorCode);

        return;
    }

    throw new RuntimeException("Expected Financial Statement Format error [{$errorCode}].");
}

test('the registry delegates to the only matching Financial Statement Format adapter', function () {
    $preview = financialStatementFormatPreview();
    $registry = new FinancialStatementFormatRegistry([
        financialStatementFormatAdapter(false, $preview),
        financialStatementFormatAdapter(true, $preview),
    ]);

    expect($registry->preview('statement text', str_repeat('a', 64)))->toBe($preview);
});

test('the registry rejects unsupported Financial Statement Formats', function () {
    $preview = financialStatementFormatPreview();
    $registry = new FinancialStatementFormatRegistry([
        financialStatementFormatAdapter(false, $preview),
    ]);

    expectFinancialStatementFormatRegistryError(
        fn () => $registry->preview('statement text', str_repeat('a', 64)),
        'unsupported_format',
    );
});

test('the registry rejects ambiguous Financial Statement Formats', function () {
    $preview = financialStatementFormatPreview();
    $registry = new FinancialStatementFormatRegistry([
        financialStatementFormatAdapter(true, $preview),
        financialStatementFormatAdapter(true, $preview),
    ]);

    expectFinancialStatementFormatRegistryError(
        fn () => $registry->preview('statement text', str_repeat('a', 64)),
        'ambiguous_format',
    );
});
