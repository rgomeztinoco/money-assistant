<?php

namespace App\StatementImports;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class StatementImportListItem implements Arrayable
{
    /** @param array<string, string> $totals */
    public function __construct(
        public int $id,
        public string $financialStatementFormat,
        public string $periodStart,
        public string $periodEnd,
        public string $instrumentLabel,
        public ?string $instrumentLastFour,
        public int $movementCount,
        public string $confirmedAt,
        public int $linkedMovementCount,
        public int $createdMovementCount,
        public int $excludedMovementCount,
        public array $totals,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'financial_statement_format' => $this->financialStatementFormat,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'instrument_label' => $this->instrumentLabel,
            'instrument_last_four' => $this->instrumentLastFour,
            'movement_count' => $this->movementCount,
            'confirmed_at' => $this->confirmedAt,
            'linked_movement_count' => $this->linkedMovementCount,
            'created_movement_count' => $this->createdMovementCount,
            'excluded_movement_count' => $this->excludedMovementCount,
            'totals' => $this->totals,
        ];
    }
}
