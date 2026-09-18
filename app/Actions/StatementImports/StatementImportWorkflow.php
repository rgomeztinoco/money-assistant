<?php

namespace App\Actions\StatementImports;

use App\CategoryAssignmentProvenance;
use App\Contracts\StatementPdfExtractor;
use App\Currency;
use App\IncomeSource;
use App\Models\Category;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\StatementImports\FinancialStatementFormatRegistry;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportPreviewMovement;
use App\StatementImports\StatementImportValidationException;
use App\StatementImports\StatementMovementMatcher;
use App\StatementMovementClassification;
use App\StatementMovementResolution;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type ValidatedMovement array{
 *     source: StatementImportPreviewMovement,
 *     occurred_on: CarbonImmutable,
 *     amount_minor: string,
 *     currency: Currency,
 *     classification: StatementMovementClassification,
 *     description: string,
 *     resolution: StatementMovementResolution,
 *     transaction_id: int|null,
 *     match_evidence: array<string, mixed>
 * }
 */
final class StatementImportWorkflow
{
    public function __construct(
        private StatementPdfExtractor $pdfExtractor,
        private FinancialStatementFormatRegistry $financialStatementFormats,
        private StatementMovementMatcher $statementMovementMatcher,
    ) {}

    public function preview(User $owner, UploadedFile $statement): StatementImportPreview
    {
        $path = $statement->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new StatementImportValidationException('The uploaded statement could not be read.', 'unreadable_pdf');
        }

        $bytes = file_get_contents($path);

        if ($bytes === false
            || $bytes === ''
            || strlen($bytes) > ((int) config('statement-imports.max_file_kilobytes') * 1024)) {
            throw new StatementImportValidationException('The statement must be a PDF no larger than 8 MB.', 'invalid_pdf_size');
        }

        if (! str_starts_with($bytes, '%PDF-')) {
            throw new StatementImportValidationException('Only valid PDF statements are supported.', 'invalid_pdf');
        }

        if (preg_match('/\/Encrypt\b/', $bytes) === 1) {
            throw new StatementImportValidationException('Remove the PDF password before importing this statement.', 'encrypted_pdf');
        }

        unset($bytes);
        $text = $this->pdfExtractor->extract($path);

        if (Str::squish($text) === '') {
            throw new StatementImportValidationException('The PDF has no selectable text. Scanned statements are not supported.', 'empty_text');
        }

        try {
            $fileHash = hash_file('sha256', $path);

            if ($fileHash === false) {
                throw new StatementImportValidationException('The uploaded statement could not be identified.', 'unreadable_pdf');
            }

            return $this->statementMovementMatcher->match(
                $owner,
                $this->financialStatementFormats->preview($text, $fileHash),
            );
        } finally {
            unset($text);
        }
    }

    /**
     * @param  array{
     *     file_hash?: mixed,
     *     instrument_label?: mixed,
     *     instrument_last_four?: mixed,
     *     movements?: mixed
     * }  $confirmation
     */
    public function confirm(User $owner, UploadedFile $statement, array $confirmation): StatementImport
    {
        $preview = $this->preview($owner, $statement);
        $validatedConfirmation = $preview->validateConfirmation($confirmation);
        $instrumentLabel = $validatedConfirmation['instrument_label'];
        $instrumentLastFour = $validatedConfirmation['instrument_last_four'];
        $editedMovements = $validatedConfirmation['movements'];

        try {
            return DB::transaction(function () use ($owner, $preview, $editedMovements, $instrumentLabel, $instrumentLastFour): StatementImport {
                $statementImport = StatementImport::create([
                    'user_id' => $owner->getKey(),
                    'financial_statement_format' => $preview->financialStatementFormat,
                    'parser_version' => $preview->parserVersion,
                    'file_hash' => $preview->fileHash,
                    'period_start' => $preview->periodStart,
                    'period_end' => $preview->periodEnd,
                    'instrument_label' => $instrumentLabel,
                    'instrument_last_four' => $instrumentLastFour,
                    'reconciliation_values' => $preview->reconciliation,
                    'excluded_values' => $this->excludedValues($editedMovements),
                    'confirmed_at' => now(),
                ]);

                $taxCategory = $this->activeCategoryNamed($owner, 'Taxes');
                $feeCategory = $this->activeCategoryNamed($owner, 'Bank Fees');

                foreach ($editedMovements as $movementIndex => $editedMovement) {
                    $sourceMovement = $editedMovement['source'];
                    $classification = $editedMovement['classification'];

                    if ($editedMovement['resolution'] === StatementMovementResolution::Excluded) {
                        continue;
                    }

                    $category = match ($classification) {
                        StatementMovementClassification::Tax => $taxCategory,
                        StatementMovementClassification::Fee => $feeCategory,
                        default => null,
                    };
                    $transaction = match ($editedMovement['resolution']) {
                        StatementMovementResolution::Linked => $this->linkableTransaction(
                            owner: $owner,
                            transactionId: $editedMovement['transaction_id'],
                            movementIndex: $movementIndex,
                        ),
                        StatementMovementResolution::Created => $this->createTransaction(
                            owner: $owner,
                            movement: $editedMovement,
                            direction: $sourceMovement->direction,
                            category: $category,
                            instrumentLabel: $instrumentLabel,
                            instrumentLastFour: $instrumentLastFour,
                        ),
                    };

                    try {
                        StatementMovement::create([
                            'statement_import_id' => $statementImport->getKey(),
                            'transaction_id' => $transaction->getKey(),
                            'source_row_id' => $sourceMovement->sourceRowId,
                            'position' => $sourceMovement->position,
                            'occurred_on' => $editedMovement['occurred_on'],
                            'amount_minor' => $editedMovement['amount_minor'],
                            'currency' => $editedMovement['currency'],
                            'direction' => $sourceMovement->direction,
                            'classification' => $classification,
                            'description' => $editedMovement['description'],
                            'source_metadata' => $sourceMovement->sourceMetadata,
                            'resolution' => $editedMovement['resolution'],
                            'match_evidence' => $editedMovement['match_evidence'],
                        ]);
                    } catch (QueryException $exception) {
                        if ($exception->getCode() === '23505'
                            && Str::contains(
                                (string) ($exception->errorInfo[2] ?? ''),
                                'statement_movements_transaction_id_unique',
                            )) {
                            throw new StatementImportValidationException(
                                'The selected Transaction was linked to another Statement Movement after preview. Refresh and review this movement again.',
                                'movement_match_changed',
                                "movements.{$movementIndex}.resolution",
                            );
                        }

                        throw $exception;
                    }
                }

                return $statementImport->load(['movements' => fn ($query) => $query->orderBy('position')]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new StatementImportValidationException('This exact statement has already been confirmed.', 'duplicate_statement');
            }

            throw $exception;
        }
    }

    private function linkableTransaction(User $owner, ?int $transactionId, int $movementIndex): Transaction
    {
        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($transactionId)
            ->whereDoesntHave('statementMovement')
            ->lockForUpdate()
            ->first();

        if ($transaction !== null) {
            return $transaction;
        }

        throw new StatementImportValidationException(
            'The selected Transaction was linked to another Statement Movement after preview. Refresh and review this movement again.',
            'movement_match_changed',
            "movements.{$movementIndex}.resolution",
        );
    }

    /**
     * @param  list<ValidatedMovement>  $movements
     * @return list<array{position: int, occurred_on: string, amount_minor: string, currency: string, direction: string, description: string, source_metadata: array<string, mixed>}>
     */
    private function excludedValues(array $movements): array
    {
        return array_values(array_map(
            fn (array $movement): array => [
                'position' => $movement['source']->position,
                'occurred_on' => $movement['occurred_on']->toDateString(),
                'amount_minor' => $movement['amount_minor'],
                'currency' => $movement['currency']->value,
                'direction' => $movement['source']->direction->value,
                'description' => $movement['description'],
                'source_metadata' => $movement['source']->sourceMetadata,
            ],
            array_filter(
                $movements,
                fn (array $movement): bool => $movement['resolution'] === StatementMovementResolution::Excluded,
            ),
        ));
    }

    /** @param ValidatedMovement $movement */
    private function createTransaction(
        User $owner,
        array $movement,
        MovementDirection $direction,
        ?Category $category,
        string $instrumentLabel,
        ?string $instrumentLastFour,
    ): Transaction {
        $classification = $movement['classification'];
        $kind = $classification->transactionKind();

        if ($kind === null) {
            throw new StatementImportValidationException('Every confirmed movement needs a transaction kind.', 'movement_needs_classification');
        }

        return Transaction::create([
            'user_id' => $owner->getKey(),
            'occurred_on' => $movement['occurred_on'],
            'amount_minor' => $movement['amount_minor'],
            'currency' => $movement['currency'],
            'kind' => $kind,
            'direction' => $direction->value,
            'income_source' => $kind === TransactionKind::Income
                ? IncomeSource::Other
                : null,
            'transfer_purpose' => $classification->transferPurpose(),
            'description' => $movement['description'],
            'instrument_label' => $instrumentLabel,
            'instrument_last_four' => $instrumentLastFour,
            'confirmed_at' => now(),
            'provisional_fields' => [],
            'category_id' => $category?->getKey(),
            'category_assignment_provenance' => $category === null
                ? null
                : CategoryAssignmentProvenance::Owner,
        ]);
    }

    private function activeCategoryNamed(User $owner, string $name): ?Category
    {
        return Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->availableForAssignment()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->first();
    }
}
