<?php

namespace App\StatementImports;

use App\Currency;
use App\ExactInteger;
use App\FinancialStatementFormat;
use App\StatementMovementClassification;
use App\StatementMovementMatchStatus;
use App\StatementMovementResolution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * @return array{
     *     file_hash: string,
     *     instrument_label: string,
     *     instrument_last_four: string|null,
     *     movements: list<array{
     *         source_row_id: string,
     *         occurred_on: string,
     *         description: string,
     *         amount_minor: string,
     *         currency: string,
     *         classification: string,
     *         resolution: 'create'|'exclude'|'link'|'needs_resolution',
     *         transaction_id: int|null
     *     }>
     * }
     */
    public function confirmationData(): array
    {
        return [
            'file_hash' => $this->fileHash,
            'instrument_label' => $this->instrumentLabel,
            'instrument_last_four' => $this->instrumentLastFour,
            'movements' => array_map(
                fn (StatementImportPreviewMovement $movement): array => $movement->confirmationData(),
                $this->movements,
            ),
        ];
    }

    /**
     * @param  array{
     *     file_hash?: mixed,
     *     instrument_label?: mixed,
     *     instrument_last_four?: mixed,
     *     movements?: mixed
     * }  $confirmation
     * @return array{
     *     instrument_label: string,
     *     instrument_last_four: string|null,
     *     movements: list<array{
     *         source: StatementImportPreviewMovement,
     *         occurred_on: CarbonImmutable,
     *         amount_minor: string,
     *         currency: Currency,
     *         classification: StatementMovementClassification,
     *         description: string,
     *         resolution: StatementMovementResolution,
     *         transaction_id: int|null,
     *         match_evidence: array<string, mixed>
     *     }>
     * }
     */
    public function validateConfirmation(array $confirmation): array
    {
        if (! is_string($confirmation['file_hash'] ?? null)
            || ! hash_equals($this->fileHash, $confirmation['file_hash'])) {
            throw $this->invalid('The confirmation PDF does not match the previewed statement.', 'file_mismatch');
        }

        $instrumentLabel = Str::squish(is_string($confirmation['instrument_label'] ?? null)
            ? $confirmation['instrument_label']
            : '');
        $instrumentLastFour = $confirmation['instrument_last_four'] ?? null;

        if ($instrumentLabel === '' || Str::length($instrumentLabel) > 100) {
            throw $this->invalid(
                'A safe payment-instrument label is required.',
                'invalid_instrument_label',
                'instrument_label',
            );
        }

        if (preg_match('/(?:\d[\s-]?){5,}/', $instrumentLabel) === 1) {
            throw $this->invalid(
                'Use a product label without a complete account or card number.',
                'unsafe_instrument_label',
                'instrument_label',
            );
        }

        if ($instrumentLastFour !== null
            && (! is_string($instrumentLastFour) || preg_match('/^\d{4}$/D', $instrumentLastFour) !== 1)) {
            throw $this->invalid(
                'Payment-instrument last four must contain exactly four digits.',
                'invalid_instrument_last_four',
                'instrument_last_four',
            );
        }

        return [
            'instrument_label' => $instrumentLabel,
            'instrument_last_four' => $instrumentLastFour,
            'movements' => $this->validateMovementEdits($confirmation['movements'] ?? null),
        ];
    }

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
            'confirmation' => $this->confirmationData(),
        ];
    }

    /**
     * @return list<array{
     *     source: StatementImportPreviewMovement,
     *     occurred_on: CarbonImmutable,
     *     amount_minor: string,
     *     currency: Currency,
     *     classification: StatementMovementClassification,
     *     description: string,
     *     resolution: StatementMovementResolution,
     *     transaction_id: int|null,
     *     match_evidence: array<string, mixed>
     * }>
     */
    private function validateMovementEdits(mixed $edits): array
    {
        if (! is_array($edits) || count($edits) !== count($this->movements)) {
            throw $this->invalid(
                'Every source movement must be included exactly once.',
                'movement_set_mismatch',
                'movements',
            );
        }

        $sourceMovements = collect($this->movements)->keyBy(
            fn (StatementImportPreviewMovement $movement): string => $movement->sourceRowId,
        );
        $seen = [];
        $linkedTransactionIds = [];
        $validated = [];

        foreach (array_values($edits) as $movementIndex => $edit) {
            if (! is_array($edit) || ! is_string($edit['source_row_id'] ?? null)) {
                throw $this->invalid(
                    'Every movement must retain its source identity.',
                    'invalid_source_row',
                    "movements.{$movementIndex}.source_row_id",
                );
            }

            $sourceRowId = $edit['source_row_id'];
            $source = $sourceMovements->get($sourceRowId);

            if (! $source instanceof StatementImportPreviewMovement || isset($seen[$sourceRowId])) {
                throw $this->invalid(
                    'A source movement was omitted, duplicated, or substituted.',
                    'movement_set_mismatch',
                    "movements.{$movementIndex}.source_row_id",
                );
            }

            $seen[$sourceRowId] = true;
            $classification = is_string($edit['classification'] ?? null)
                ? StatementMovementClassification::tryFrom($edit['classification'])
                : null;

            if ($classification === StatementMovementClassification::NotAMovement) {
                if (! $source->canBeExcluded) {
                    throw $this->invalid(
                        'A posted movement cannot be removed from the import.',
                        'movement_cannot_be_excluded',
                        "movements.{$movementIndex}.classification",
                    );
                }

                $validated[] = [
                    'source' => $source,
                    'occurred_on' => $source->occurredOn,
                    'amount_minor' => $source->amountMinor,
                    'currency' => $source->currency,
                    'classification' => $classification,
                    'description' => $source->description,
                    'resolution' => StatementMovementResolution::Excluded,
                    'transaction_id' => null,
                    'match_evidence' => [],
                ];

                continue;
            }

            $occurredOn = $this->strictDate(
                $edit['occurred_on'] ?? null,
                "movements.{$movementIndex}.occurred_on",
            );
            $amountMinor = $this->positiveMinorUnits(
                $edit['amount_minor'] ?? null,
                "movements.{$movementIndex}.amount_minor",
            );
            $currency = is_string($edit['currency'] ?? null)
                ? Currency::tryFrom($edit['currency'])
                : null;
            $description = Str::squish(is_string($edit['description'] ?? null) ? $edit['description'] : '');

            if ($currency === null) {
                throw $this->invalid(
                    'A movement has an unsupported currency.',
                    'invalid_movement_currency',
                    "movements.{$movementIndex}.currency",
                );
            }

            if ($classification === null || in_array($classification, [
                StatementMovementClassification::NeedsClassification,
                StatementMovementClassification::AlreadyRecorded,
            ], true)) {
                throw $this->invalid(
                    'Classify every real movement before confirming the import.',
                    'movement_needs_classification',
                    "movements.{$movementIndex}.classification",
                );
            }

            if ($description === '' || Str::length($description) > 255) {
                throw $this->invalid(
                    'Every movement requires a short description.',
                    'invalid_movement_description',
                    "movements.{$movementIndex}.description",
                );
            }

            [$resolution, $transactionId, $matchEvidence] = $this->validateResolution(
                source: $source,
                edit: $edit,
                movementIndex: $movementIndex,
                classification: $classification,
            );

            if ($resolution === StatementMovementResolution::Linked
                && $transactionId !== null
                && isset($linkedTransactionIds[$transactionId])) {
                throw $this->invalid(
                    'Each Transaction can resolve only one statement movement.',
                    'duplicate_movement_match',
                    "movements.{$movementIndex}.resolution",
                );
            }

            if ($resolution === StatementMovementResolution::Linked && $transactionId !== null) {
                $linkedTransactionIds[$transactionId] = true;
            }

            $validated[] = [
                'source' => $source,
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'classification' => $classification,
                'description' => $description,
                'resolution' => $resolution,
                'transaction_id' => $transactionId,
                'match_evidence' => $matchEvidence,
            ];
        }

        if (count($seen) !== $sourceMovements->count()) {
            throw $this->invalid(
                'Every source movement must be included exactly once.',
                'movement_set_mismatch',
                'movements',
            );
        }

        usort($validated, fn (array $left, array $right): int => $left['source']->position <=> $right['source']->position);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $edit
     * @return array{StatementMovementResolution, int|null, array<string, mixed>}
     */
    private function validateResolution(
        StatementImportPreviewMovement $source,
        array $edit,
        int $movementIndex,
        StatementMovementClassification $classification,
    ): array {
        $match = $source->match ?? StatementMovementMatch::fresh();
        $resolution = $edit['resolution'] ?? null;
        $transactionIdInput = $edit['transaction_id'] ?? null;
        $transactionId = $this->positiveInteger($transactionIdInput);
        $validationField = "movements.{$movementIndex}.resolution";

        if ($match->status === StatementMovementMatchStatus::Matched) {
            if ($resolution !== 'link'
                || ! is_int($transactionId)
                || $transactionId !== $match->transactionId) {
                throw $this->invalid(
                    'The clear statement match changed after preview.',
                    'movement_match_changed',
                    $validationField,
                );
            }

            if ($match->compatibleCandidate($transactionId, $classification) === null) {
                throw $this->invalid(
                    $match->incompatibilityMessage($transactionId, $classification)
                        ?? 'The selected Transaction is not compatible with this statement movement.',
                    'invalid_movement_match',
                    $validationField,
                );
            }

            return [StatementMovementResolution::Linked, $transactionId, $match->evidence];
        }

        if ($match->status === StatementMovementMatchStatus::New) {
            if ($resolution !== 'create'
                || $transactionId !== null
                || $transactionIdInput !== null) {
                throw $this->invalid(
                    'A statement gap must be added as a new Transaction.',
                    'invalid_movement_resolution',
                    $validationField,
                );
            }

            return [StatementMovementResolution::Created, null, []];
        }

        if ($resolution === 'needs_resolution') {
            throw $this->invalid(
                'Choose whether to link or add this ambiguous movement.',
                'movement_needs_match_resolution',
                $validationField,
            );
        }

        if ($resolution === 'create' && $transactionId === null) {
            if ($transactionIdInput !== null) {
                throw $this->invalid(
                    'Remove the selected Transaction before adding this movement as new.',
                    'invalid_movement_resolution',
                    $validationField,
                );
            }

            return [StatementMovementResolution::Created, null, ['owner_confirmed_new' => true]];
        }

        $candidate = is_int($transactionId)
            ? $match->compatibleCandidate($transactionId, $classification)
            : null;

        if ($resolution !== 'link'
            || ! is_int($transactionId)
            || $candidate === null) {
            throw $this->invalid(
                $transactionId === null
                    ? 'Choose a proposed Transaction to link to this statement movement.'
                    : ($match->incompatibilityMessage($transactionId, $classification)
                        ?? 'The selected Transaction is not compatible with this statement movement.'),
                'invalid_movement_match',
                $validationField,
            );
        }

        return [
            StatementMovementResolution::Linked,
            $transactionId,
            [
                ...$candidate['evidence'],
                'date_difference_days' => $candidate['date_difference_days'],
                'owner_confirmed' => true,
            ],
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($integer) ? $integer : null;
    }

    private function strictDate(mixed $date, string $validationField): CarbonImmutable
    {
        if (! is_string($date)) {
            throw $this->invalid('Every movement requires a valid date.', 'invalid_movement_date', $validationField);
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));

        if ($parsed === null || $parsed->toDateString() !== $date) {
            throw $this->invalid('Every movement requires a valid date.', 'invalid_movement_date', $validationField);
        }

        return $parsed;
    }

    private function positiveMinorUnits(mixed $amount, string $validationField): string
    {
        if (! is_int($amount) && ! is_string($amount)) {
            throw $this->invalid(
                'Movement amounts must use positive integer minor units.',
                'invalid_movement_amount',
                $validationField,
            );
        }

        try {
            $exact = ExactInteger::from($amount);
        } catch (Throwable) {
            throw $this->invalid(
                'Movement amounts must use positive integer minor units.',
                'invalid_movement_amount',
                $validationField,
            );
        }

        if ($exact->compare(ExactInteger::from(0)) !== 1
            || $exact->compare(ExactInteger::from('9223372036854775807')) === 1) {
            throw $this->invalid(
                'Movement amounts must use positive integer minor units.',
                'invalid_movement_amount',
                $validationField,
            );
        }

        return $exact->value();
    }

    private function invalid(
        string $message,
        string $errorCode,
        string $validationField = 'statement',
    ): StatementImportValidationException {
        return new StatementImportValidationException($message, $errorCode, $validationField);
    }
}
