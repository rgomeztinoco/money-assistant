<?php

namespace App\StatementImports;

use App\Contracts\StatementPdfExtractor;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final class ProcessStatementPdfExtractor implements StatementPdfExtractor
{
    private const int PAGE_LIMIT_EXIT_CODE = 20;

    private const int EXTRACTION_LIMIT_EXIT_CODE = 21;

    private const int CORRUPT_PDF_EXIT_CODE = 22;

    public function extract(string $path): string
    {
        try {
            $result = Process::timeout((int) config('statement-imports.processing_timeout_seconds'))
                ->run($this->command($path));
        } catch (ProcessTimedOutException) {
            throw $this->invalid(
                'The statement took too long to process.',
                'processing_limit',
            );
        }

        if ($result->successful()) {
            return $result->output();
        }

        throw match ($result->exitCode()) {
            self::PAGE_LIMIT_EXIT_CODE => $this->invalid(
                'The statement has an unsupported number of pages.',
                'page_limit',
            ),
            self::EXTRACTION_LIMIT_EXIT_CODE => $this->invalid(
                'The extracted statement is too large to process safely.',
                'extraction_limit',
            ),
            self::CORRUPT_PDF_EXIT_CODE => $this->invalid(
                'The PDF is corrupt or cannot be read.',
                'corrupt_pdf',
            ),
            default => $this->invalid(
                'The PDF is corrupt or cannot be read.',
                'corrupt_pdf',
            ),
        };
    }

    /** @return list<string> */
    private function command(string $path): array
    {
        $worker = <<<'PHP'
        require $argv[1];

        try {
            $pdf = (new Smalot\PdfParser\Parser())->parseFile($argv[2]);

            if (count($pdf->getPages()) > (int) $argv[3]) {
                exit(20);
            }

            $text = $pdf->getText();

            if (strlen($text) > (int) $argv[4]) {
                exit(21);
            }

            fwrite(STDOUT, $text);
        } catch (Throwable) {
            exit(22);
        }
        PHP;

        return [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-r',
            $worker,
            base_path('vendor/autoload.php'),
            $path,
            (string) config('statement-imports.max_pages'),
            (string) config('statement-imports.max_extracted_bytes'),
        ];
    }

    private function invalid(string $message, string $errorCode): StatementImportValidationException
    {
        return new StatementImportValidationException($message, $errorCode);
    }
}
