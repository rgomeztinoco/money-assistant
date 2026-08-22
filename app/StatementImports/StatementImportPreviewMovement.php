<?php

namespace App\StatementImports;

use App\Currency;
use App\MovementDirection;
use App\StatementMovementClassification;
use Carbon\CarbonImmutable;

final readonly class StatementImportPreviewMovement
{
    /** @param  array<string, mixed>  $sourceMetadata */
    public function __construct(
        public string $sourceRowId,
        public int $position,
        public CarbonImmutable $occurredOn,
        public string $description,
        public string $amountMinor,
        public Currency $currency,
        public MovementDirection $direction,
        public StatementMovementClassification $classification,
        public bool $contributesToSpending,
        public bool $canBeExcluded,
        public array $sourceMetadata,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_row_id' => $this->sourceRowId,
            'position' => $this->position,
            'occurred_on' => $this->occurredOn->toDateString(),
            'description' => $this->description,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency->value,
            'direction' => $this->direction->value,
            'classification' => $this->classification->value,
            'contributes_to_spending' => $this->contributesToSpending,
            'can_be_excluded' => $this->canBeExcluded,
            'source_metadata' => $this->sourceMetadata,
        ];
    }
}
