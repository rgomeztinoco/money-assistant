<?php

namespace App\StatementImports;

use App\FinancialStatementFormat;
use Carbon\CarbonImmutable;

final readonly class StatementImportPreview
{
    /**
     * @param  list<StatementImportPreviewMovement>  $movements
     * @param  list<array{label: string, value: string, currency: string}>  $informationalValues
     * @param  array<string, string>  $reconciliation
     */
    public function __construct(
        public FinancialStatementFormat $financialStatementFormat,
        public string $parserVersion,
        public string $fileHash,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $instrumentLabel,
        public ?string $instrumentLastFour,
        public array $movements,
        public array $informationalValues,
        public array $reconciliation,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'financial_statement_format' => $this->financialStatementFormat->value,
            'parser_version' => $this->parserVersion,
            'file_hash' => $this->fileHash,
            'period_start' => $this->periodStart->toDateString(),
            'period_end' => $this->periodEnd->toDateString(),
            'instrument_label' => $this->instrumentLabel,
            'instrument_last_four' => $this->instrumentLastFour,
            'movements' => array_map(
                fn (StatementImportPreviewMovement $movement): array => $movement->toArray(),
                $this->movements,
            ),
            'informational_values' => $this->informationalValues,
            'reconciliation' => $this->reconciliation,
        ];
    }
}
