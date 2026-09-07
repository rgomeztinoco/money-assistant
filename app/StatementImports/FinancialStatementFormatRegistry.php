<?php

namespace App\StatementImports;

use App\Contracts\StatementImports\FinancialStatementFormatAdapter;

final readonly class FinancialStatementFormatRegistry
{
    /** @param list<FinancialStatementFormatAdapter> $adapters */
    public function __construct(private array $adapters) {}

    public function preview(string $statementText, string $fileHash): StatementImportPreview
    {
        $matchingAdapters = array_values(array_filter(
            $this->adapters,
            fn (FinancialStatementFormatAdapter $adapter): bool => $adapter->matches($statementText),
        ));

        if ($matchingAdapters === []) {
            throw new StatementImportValidationException(
                'This Financial Statement Format is not supported.',
                'unsupported_format',
            );
        }

        if (count($matchingAdapters) > 1) {
            throw new StatementImportValidationException(
                'The Financial Statement Format could not be identified safely.',
                'ambiguous_format',
            );
        }

        return $matchingAdapters[0]->preview($statementText, $fileHash);
    }
}
