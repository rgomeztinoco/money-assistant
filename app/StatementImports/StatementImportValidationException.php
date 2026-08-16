<?php

namespace App\StatementImports;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class StatementImportValidationException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $validationField = 'statement',
    ) {
        parent::__construct($message);
    }
}
