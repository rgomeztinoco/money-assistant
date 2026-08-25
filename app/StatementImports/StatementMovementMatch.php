<?php

namespace App\StatementImports;

use App\StatementMovementClassification;
use App\StatementMovementMatchStatus;

final readonly class StatementMovementMatch
{
    /**
     * @param  list<array{id: int, occurred_on: string, description: string, instrument_label: string|null, instrument_last_four: string|null, kind: string, transfer_purpose: string|null, compatible_classifications: list<string>, date_difference_days: int, evidence: array<string, bool>}>  $candidates
     * @param  array<string, bool|int|string|null>  $evidence
     */
    public function __construct(
        public StatementMovementMatchStatus $status,
        public ?int $transactionId,
        public array $candidates,
        public array $evidence,
    ) {}

    public static function fresh(): self
    {
        return new self(StatementMovementMatchStatus::New, null, [], []);
    }

    /**
     * @return array{id: int, occurred_on: string, description: string, instrument_label: string|null, instrument_last_four: string|null, kind: string, transfer_purpose: string|null, compatible_classifications: list<string>, date_difference_days: int, evidence: array<string, bool>}|null
     */
    public function compatibleCandidate(
        int $transactionId,
        StatementMovementClassification $classification,
    ): ?array {
        $candidate = collect($this->candidates)->firstWhere('id', $transactionId);

        if (! is_array($candidate)
            || $candidate['kind'] !== $classification->transactionKind()?->value
            || ($classification->transferPurpose() !== null
                && $candidate['transfer_purpose'] !== $classification->transferPurpose()->value)) {
            return null;
        }

        return $candidate;
    }

    public function incompatibilityMessage(
        int $transactionId,
        StatementMovementClassification $classification,
    ): ?string {
        $candidate = collect($this->candidates)->firstWhere('id', $transactionId);

        if (! is_array($candidate)) {
            return 'The selected Transaction is no longer a proposed match. Upload the statement again to refresh the matches.';
        }

        $requiredKind = $classification->transactionKind();

        if ($requiredKind === null) {
            return 'Classify this statement movement before linking it to a Transaction.';
        }

        if ($candidate['kind'] !== $requiredKind->value) {
            return sprintf(
                'The selected Transaction is recorded as %s, but this statement movement is classified as %s. Change the classification or choose a Transaction recorded as %s.',
                $this->kindLabel($candidate['kind']),
                $this->classificationLabel($classification),
                $this->kindLabel($requiredKind->value),
            );
        }

        $requiredTransferPurpose = $classification->transferPurpose();

        if ($requiredTransferPurpose !== null
            && $candidate['transfer_purpose'] !== $requiredTransferPurpose->value) {
            return sprintf(
                'The selected Transaction is recorded as %s, but this statement movement is classified as %s. Change the classification or choose a matching Transfer.',
                $this->transferPurposeLabel($candidate['transfer_purpose']),
                $this->classificationLabel($classification),
            );
        }

        return null;
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'spending' => 'Spending',
            'refund' => 'a Refund or reimbursement',
            'income' => 'Income',
            'transfer' => 'a Transfer',
            default => $kind,
        };
    }

    private function classificationLabel(StatementMovementClassification $classification): string
    {
        return match ($classification) {
            StatementMovementClassification::Purchase => 'Spending',
            StatementMovementClassification::Refund => 'a Refund or reimbursement',
            StatementMovementClassification::Fee => 'a Bank fee',
            StatementMovementClassification::Tax => 'Tax',
            StatementMovementClassification::Income => 'Income',
            StatementMovementClassification::Transfer => 'a Transfer between your accounts',
            StatementMovementClassification::CardPayment => 'a Card payment',
            StatementMovementClassification::Savings => 'Savings',
            default => $classification->value,
        };
    }

    private function transferPurposeLabel(?string $transferPurpose): string
    {
        return match ($transferPurpose) {
            'savings' => 'Savings',
            'card_payment' => 'a Card payment',
            'internal' => 'a Transfer between your accounts',
            default => 'a Transfer without the required purpose',
        };
    }
}
