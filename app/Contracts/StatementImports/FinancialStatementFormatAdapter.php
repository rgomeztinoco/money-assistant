<?php

namespace App\Contracts\StatementImports;

use App\StatementImports\StatementImportPreview;

interface FinancialStatementFormatAdapter
{
    public function matches(string $statementText): bool;

    public function preview(string $statementText, string $fileHash): StatementImportPreview;
}
