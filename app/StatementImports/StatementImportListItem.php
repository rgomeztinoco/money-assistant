<?php

namespace App\StatementImports;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class StatementImportListItem implements Arrayable
{
    /** @param array<string, string> $totals */
    public function __construct(
        public int $id,
        public string $provider,
        public string $periodStart,
        public string $periodEnd,
        public string $instrumentLabel,
        public ?string $instrumentLastFour,
        public int $movementCount,
        public string $confirmedAt,
        public array $totals,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'instrument_label' => $this->instrumentLabel,
            'instrument_last_four' => $this->instrumentLastFour,
            'movement_count' => $this->movementCount,
            'confirmed_at' => $this->confirmedAt,
            'totals' => $this->totals,
        ];
    }
}
