<?php

namespace App\StatementImports;

use App\Currency;
use App\MovementDirection;
use App\StatementMovementClassification;
use App\StatementMovementMatchStatus;
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
        public ?StatementMovementMatch $match = null,
    ) {}

    public function withMatch(StatementMovementMatch $match): self
    {
        return new self(
            sourceRowId: $this->sourceRowId,
            position: $this->position,
            occurredOn: $this->occurredOn,
            description: $this->description,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            direction: $this->direction,
            classification: $this->classification,
            contributesToSpending: $this->contributesToSpending,
            canBeExcluded: $this->canBeExcluded,
            sourceMetadata: $this->sourceMetadata,
            match: $match,
        );
    }

    /**
     * @return array{
     *     source_row_id: string,
     *     occurred_on: string,
     *     description: string,
     *     amount_minor: string,
     *     currency: string,
     *     classification: string,
     *     resolution: 'create'|'exclude'|'link'|'needs_resolution',
     *     transaction_id: int|null
     * }
     */
    public function confirmationData(): array
    {
        return [
            'source_row_id' => $this->sourceRowId,
            'occurred_on' => $this->occurredOn->toDateString(),
            'description' => $this->description,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency->value,
            'classification' => $this->classification->value,
            'resolution' => $this->classification === StatementMovementClassification::NotAMovement
                ? 'exclude'
                : match ($this->match?->status) {
                    StatementMovementMatchStatus::Matched => 'link',
                    StatementMovementMatchStatus::Ambiguous => 'needs_resolution',
                    default => 'create',
                },
            'transaction_id' => $this->match?->transactionId,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $match = $this->match ?? StatementMovementMatch::fresh();

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
            'match' => [
                'status' => $match->status->value,
                'transaction_id' => $match->transactionId,
                'candidates' => $match->candidates,
                'evidence' => $match->evidence,
            ],
        ];
    }
}
