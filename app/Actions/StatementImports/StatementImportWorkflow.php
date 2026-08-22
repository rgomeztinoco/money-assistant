<?php

namespace App\Actions\StatementImports;

use App\CategoryAssignmentProvenance;
use App\Contracts\StatementPdfExtractor;
use App\Currency;
use App\ExactInteger;
use App\FinancialStatementFormat;
use App\IncomeSource;
use App\Models\Category;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportPreviewMovement;
use App\StatementImports\StatementImportValidationException;
use App\StatementMovementClassification;
use App\StatementMovementDirection;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type ValidatedMovement array{
 *     source: StatementImportPreviewMovement,
 *     occurred_on: CarbonImmutable,
 *     amount_minor: string,
 *     currency: Currency,
 *     classification: StatementMovementClassification,
 *     description: string
 * }
 */
final class StatementImportWorkflow
{
    public function __construct(private StatementPdfExtractor $pdfExtractor) {}

    public function preview(User $owner, UploadedFile $statement): StatementImportPreview
    {
        unset($owner);

        $path = $statement->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw $this->invalid('The uploaded statement could not be read.', 'unreadable_pdf');
        }

        $bytes = file_get_contents($path);

        if ($bytes === false
            || $bytes === ''
            || strlen($bytes) > ((int) config('statement-imports.max_file_kilobytes') * 1024)) {
            throw $this->invalid('The statement must be a PDF no larger than 8 MB.', 'invalid_pdf_size');
        }

        if (! str_starts_with($bytes, '%PDF-')) {
            throw $this->invalid('Only valid PDF statements are supported.', 'invalid_pdf');
        }

        if (preg_match('/\/Encrypt\b/', $bytes) === 1) {
            throw $this->invalid('Remove the PDF password before importing this statement.', 'encrypted_pdf');
        }

        unset($bytes);
        $text = $this->pdfExtractor->extract($path);

        if (Str::squish($text) === '') {
            throw $this->invalid('The PDF has no selectable text. Scanned statements are not supported.', 'empty_text');
        }

        try {
            $fileHash = hash_file('sha256', $path);

            if ($fileHash === false) {
                throw $this->invalid('The uploaded statement could not be identified.', 'unreadable_pdf');
            }

            if ($this->isBcpStatement($text)) {
                $preview = $this->parseBcp($text, $fileHash);
            } elseif ($this->isInterbankStatement($text)) {
                $preview = $this->parseInterbank($text, $fileHash);
            } else {
                throw $this->invalid('This Financial Statement Format is not supported.', 'unsupported_format');
            }

            return $preview;
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

        if (! is_string($confirmation['file_hash'] ?? null)
            || ! hash_equals($preview->fileHash, $confirmation['file_hash'])) {
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

        $editedMovements = $this->validateMovementEdits($preview, $confirmation['movements'] ?? null);
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
                    'movement_count' => count($editedMovements),
                    'confirmed_at' => now(),
                ]);

                $taxCategory = $this->activeCategoryNamed($owner, 'Taxes');
                $feeCategory = $this->activeCategoryNamed($owner, 'Bank Fees');

                foreach ($editedMovements as $editedMovement) {
                    $sourceMovement = $editedMovement['source'];
                    $classification = $editedMovement['classification'];
                    $category = match ($classification) {
                        StatementMovementClassification::Tax => $taxCategory,
                        StatementMovementClassification::Fee => $feeCategory,
                        default => null,
                    };
                    $transaction = $this->createTransaction(
                        owner: $owner,
                        movement: $editedMovement,
                        direction: $sourceMovement->direction,
                        category: $category,
                        instrumentLabel: $instrumentLabel,
                        instrumentLastFour: $instrumentLastFour,
                    );

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
                        'instrument_label' => $instrumentLabel,
                        'instrument_last_four' => $instrumentLastFour,
                        'source_metadata' => $sourceMovement->sourceMetadata,
                    ]);
                }

                return $statementImport->load(['movements' => fn ($query) => $query->orderBy('position')]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw $this->invalid('This exact statement has already been confirmed.', 'duplicate_statement');
            }

            throw $exception;
        }
    }

    /**
     * @return list<ValidatedMovement>
     */
    private function validateMovementEdits(StatementImportPreview $preview, mixed $edits): array
    {
        if (! is_array($edits) || count($edits) !== count($preview->movements)) {
            throw $this->invalid(
                'Every source movement must be included exactly once.',
                'movement_set_mismatch',
                'movements',
            );
        }

        $sourceMovements = collect($preview->movements)->keyBy(
            fn (StatementImportPreviewMovement $movement): string => $movement->sourceRowId,
        );
        $seen = [];
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

            $validated[] = [
                'source' => $source,
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'classification' => $classification,
                'description' => $description,
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

    /** @param ValidatedMovement $movement */
    private function createTransaction(
        User $owner,
        array $movement,
        StatementMovementDirection $direction,
        ?Category $category,
        string $instrumentLabel,
        ?string $instrumentLastFour,
    ): Transaction {
        $classification = $movement['classification'];
        $kind = $classification->transactionKind();

        if ($kind === null) {
            throw $this->invalid('Every confirmed movement needs a financial meaning.', 'movement_needs_classification');
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
            'merchant_description' => $movement['description'],
            'payment_instrument_label' => $instrumentLabel,
            'payment_instrument_last_four' => $instrumentLastFour,
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

    private function strictDate(mixed $date, string $validationField = 'statement'): CarbonImmutable
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

    private function positiveMinorUnits(mixed $amount, string $validationField = 'statement'): string
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

    private function isBcpStatement(string $text): bool
    {
        return Str::containsAll($text, [
            'Estado de Cuenta de Ahorros',
            'CARGOS / DEBE',
            'ABONOS / HABER',
        ]);
    }

    private function isInterbankStatement(string $text): bool
    {
        return Str::containsAll($text, [
            'DETALLE DE PAGO DEL MES',
            'TUS CONSUMOS',
            'PAGO DEL MES',
        ]);
    }

    private function parseBcp(string $text, string $fileHash): StatementImportPreview
    {
        if (preg_match('/DEL\s+(\d{2}\/\d{2}\/\d{2,4})\s+AL\s+(\d{2}\/\d{2}\/\d{2,4})/u', $text, $period) !== 1) {
            throw $this->invalid('The BCP statement period could not be read.', 'invalid_period');
        }

        $periodStart = $this->parseNumericDate($period[1]);
        $periodEnd = $this->parseNumericDate($period[2]);
        $lines = preg_split('/\R/u', $text) ?: [];
        $debitColumn = null;
        $creditColumn = null;
        $directionBoundary = null;
        $openingBalance = null;
        $printedDebits = null;
        $printedCredits = null;
        $closingBalance = null;
        $totalsFound = false;
        $movements = [];
        $informationalValues = [];

        foreach ($lines as $line) {
            if (Str::contains($line, ['CARGOS / DEBE', 'ABONOS / HABER'])) {
                $debitColumn = strpos($line, 'CARGOS / DEBE');
                $creditColumn = strpos($line, 'ABONOS / HABER');

                if ($debitColumn === false || $creditColumn === false || $debitColumn >= $creditColumn) {
                    throw $this->invalid('The BCP movement columns could not be read.', 'invalid_bcp_columns');
                }

                $directionBoundary = null;

                continue;
            }

            if (preg_match('/SALDO ANTERIOR\s+([\d,]+\.\d{2})/u', $line, $opening) === 1) {
                $openingBalance = $this->minorUnits($opening[1]);

                continue;
            }

            if (preg_match('/^\s*([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*$/u', $line, $totals) === 1) {
                $printedDebits = $this->minorUnits($totals[1]);
                $printedCredits = $this->minorUnits($totals[2]);
                $totalsFound = true;

                continue;
            }

            if ($totalsFound && $closingBalance === null && preg_match('/^\s*([\d,]+\.\d{2})\s*$/u', $line, $closing) === 1) {
                $closingBalance = $this->minorUnits($closing[1]);

                continue;
            }

            if (preg_match('/^(\d{2})([A-Z]{3})\s+\d{2}[A-Z]{3}\s+/u', $line, $dateMatch) !== 1) {
                continue;
            }

            preg_match_all('/[\d,]+\.\d{2}/u', $line, $amountMatches, PREG_OFFSET_CAPTURE);
            $amountCapture = end($amountMatches[0]);

            if (! is_array($amountCapture)) {
                continue;
            }

            [$amount, $amountOffset] = $amountCapture;
            $amountMinor = $this->minorUnits($amount);
            $description = Str::of(substr($line, 12, $amountOffset - 12))
                ->replace('*', '')
                ->squish()
                ->toString();

            if ($debitColumn === null || $creditColumn === null) {
                throw $this->invalid('The BCP movement columns could not be read.', 'invalid_bcp_columns');
            }

            $directionBoundary ??= $this->bcpDirectionBoundary($lines, $debitColumn, $creditColumn);
            $direction = $amountOffset >= $directionBoundary
                    ? StatementMovementDirection::Credit
                    : StatementMovementDirection::Debit;
            $position = count($movements) + 1;
            $occurredOn = $this->bcpMovementDate(
                (int) $dateMatch[1],
                $dateMatch[2],
                $periodStart,
                $periodEnd,
            );
            $canBeExcluded = $amountMinor === '0';
            $classification = $canBeExcluded
                ? StatementMovementClassification::NotAMovement
                : $this->classifyBcp($description);

            $movements[] = new StatementImportPreviewMovement(
                sourceRowId: hash('sha256', "bcp|{$position}|{$line}"),
                position: $position,
                occurredOn: $occurredOn,
                description: $description,
                amountMinor: $amountMinor,
                currency: Currency::Pen,
                direction: $direction,
                classification: $classification,
                contributesToSpending: $classification->contributesToSpending(),
                canBeExcluded: $canBeExcluded,
                sourceMetadata: ['asterisk' => str_contains($line, '*')],
            );
        }

        if ($openingBalance === null || $printedDebits === null || $printedCredits === null || $closingBalance === null) {
            throw $this->invalid('The BCP statement totals could not be read.', 'missing_reconciliation');
        }

        $parsedDebits = $this->sumMovements($movements, StatementMovementDirection::Debit);
        $parsedCredits = $this->sumMovements($movements, StatementMovementDirection::Credit);
        $expectedClosing = ExactInteger::from($openingBalance)
            ->add(ExactInteger::from($printedCredits))
            ->subtract(ExactInteger::from($printedDebits))
            ->value();

        if ($parsedDebits !== $printedDebits) {
            throw $this->invalid('BCP Cargos do not reconcile with the parsed movements.', 'bcp_debits_mismatch');
        }

        if ($parsedCredits !== $printedCredits) {
            throw $this->invalid('BCP Abonos do not reconcile with the parsed movements.', 'bcp_credits_mismatch');
        }

        if ($expectedClosing !== $closingBalance) {
            throw $this->invalid('The BCP opening balance and totals do not reconcile with the closing balance.', 'bcp_balance_mismatch');
        }

        return new StatementImportPreview(
            financialStatementFormat: FinancialStatementFormat::Bcp,
            parserVersion: 'bcp-v1',
            fileHash: $fileHash,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            instrumentLabel: 'BCP Cuenta Digital',
            instrumentLastFour: $this->bcpLastFour($text),
            movements: $movements,
            informationalValues: $informationalValues,
            reconciliation: [
                'opening_balance_minor' => $openingBalance,
                'debits_minor' => $printedDebits,
                'credits_minor' => $printedCredits,
                'closing_balance_minor' => $closingBalance,
            ],
        );
    }

    private function parseInterbank(string $text, string $fileHash): StatementImportPreview
    {
        if (preg_match('/Tarjeta de Cr(?:é|e)dito del\s+(\d{2}\/\d{2}\/\d{4})\s+al cierre de\s+(\d{2}\/\d{2}\/\d{4})/ui', $text, $period) !== 1) {
            throw $this->invalid('The Interbank statement period could not be read.', 'invalid_period');
        }

        $periodStart = $this->parseNumericDate($period[1]);
        $periodEnd = $this->parseNumericDate($period[2]);
        $lines = preg_split('/\R/u', $text) ?: [];
        $section = null;
        $previous = ['PEN' => null, 'USD' => null];
        $printedPayments = ['PEN' => null, 'USD' => null];
        $printedConsumption = ['PEN' => null, 'USD' => null];
        $printedOtherCharges = ['PEN' => null, 'USD' => null];
        $printedPaymentTotal = ['PEN' => null, 'USD' => null];
        $currencyBoundary = null;
        $sectionStartSums = ['PEN' => ExactInteger::from(0), 'USD' => ExactInteger::from(0)];
        $sectionSums = [
            'payments' => ['PEN' => ExactInteger::from(0), 'USD' => ExactInteger::from(0)],
            'consumption' => ['PEN' => ExactInteger::from(0), 'USD' => ExactInteger::from(0)],
            'other_charges' => ['PEN' => ExactInteger::from(0), 'USD' => ExactInteger::from(0)],
        ];
        $movements = [];
        $informationalValues = [];

        foreach ($lines as $line) {
            $normalizedLine = Str::squish($line);

            if (str_contains($line, 'S/') && ($usdHeader = strpos($line, 'US$')) !== false) {
                $currencyBoundary = $usdHeader + 2;
            }

            if (Str::startsWith($normalizedLine, 'Debías en el estado de cuenta anterior')) {
                $amounts = $this->interbankAmounts($line);
                $previous = $this->completeCurrencyPair($amounts);

                continue;
            }

            if ($normalizedLine === 'PAGOS REALIZADOS') {
                if ($section !== 'payments') {
                    $sectionStartSums = $sectionSums['payments'];
                }
                $section = 'payments';

                continue;
            }

            if ($normalizedLine === 'TUS CONSUMOS'
                || $normalizedLine === 'TUS CONSUMOS EN CUOTAS'
                || Str::endsWith($normalizedLine, 'TUS CONSUMOS')) {
                if ($section !== 'consumption') {
                    $sectionStartSums = $sectionSums['consumption'];
                }
                $section = 'consumption';

                continue;
            }

            if (Str::startsWith($normalizedLine, 'OTROS COBROS')) {
                if ($section !== 'other_charges') {
                    $sectionStartSums = $sectionSums['other_charges'];
                }
                $section = 'other_charges';

                continue;
            }

            if (Str::startsWith($normalizedLine, 'PAGO MÍNIMO DEL MES')) {
                $minimumPayment = $this->completeCurrencyPair($this->interbankAmounts($line));

                foreach (['PEN', 'USD'] as $currency) {
                    if ($minimumPayment[$currency] !== null) {
                        $informationalValues[] = [
                            'label' => 'Minimum payment',
                            'value' => $minimumPayment[$currency],
                            'currency' => $currency,
                        ];
                    }
                }

                continue;
            }

            if (Str::startsWith($normalizedLine, 'PAGO DEL MES')) {
                $printedPaymentTotal = $this->completeCurrencyPair($this->interbankAmounts($line));
                $section = null;

                continue;
            }

            if (Str::startsWith($normalizedLine, 'SUBTOTAL')) {
                $subtotal = $this->completeCurrencyPair($this->interbankAmounts($line));
                $expectedSubtotal = match ($section) {
                    'payments' => [
                        'PEN' => ExactInteger::from($previous['PEN'] ?? 0)
                            ->add($sectionSums['payments']['PEN'])
                            ->value(),
                        'USD' => ExactInteger::from($previous['USD'] ?? 0)
                            ->add($sectionSums['payments']['USD'])
                            ->value(),
                    ],
                    'consumption', 'other_charges' => [
                        'PEN' => $sectionSums[$section]['PEN']->subtract($sectionStartSums['PEN'])->value(),
                        'USD' => $sectionSums[$section]['USD']->subtract($sectionStartSums['USD'])->value(),
                    ],
                    default => ['PEN' => null, 'USD' => null],
                };
                $subtotal = $this->resolveJoinedInterbankSubtotalSign(
                    $line,
                    $subtotal,
                    $expectedSubtotal,
                );

                match ($section) {
                    'payments' => $printedPayments = $subtotal,
                    'consumption' => $printedConsumption = $this->addInterbankCurrencyPairs(
                        $printedConsumption,
                        $subtotal,
                    ),
                    'other_charges' => $printedOtherCharges = $subtotal,
                    default => null,
                };
                $section = null;

                continue;
            }

            if ($section === null
                || preg_match('/^(\d{2})-([A-Za-z]{3})\s+(.+?)\s+(-?[\d,]+\.\d{2})(?:\s+(-?[\d,]+\.\d{2}))?\s*$/u', $line, $row) !== 1) {
                continue;
            }

            $description = Str::squish($row[3]);
            preg_match_all('/-?[\d,]+\.\d{2}/u', $line, $amountCaptures, PREG_OFFSET_CAPTURE);
            $rowAmountCount = isset($row[5]) ? 2 : 1;
            $rowAmountCaptures = array_slice($amountCaptures[0], -$rowAmountCount);
            $firstAmountCapture = $rowAmountCaptures[0] ?? null;

            if (! is_array($firstAmountCapture)) {
                throw $this->invalid('An Interbank row amount could not be located.', 'invalid_currency_columns');
            }

            $firstAmountOffset = $firstAmountCapture[1];

            if (isset($row[5])) {
                $secondAmountCapture = $rowAmountCaptures[1] ?? null;

                if (! is_array($secondAmountCapture)) {
                    throw $this->invalid('An Interbank row amount could not be located.', 'invalid_currency_columns');
                }

                $secondAmountOffset = $secondAmountCapture[1];
                $currencyBoundary = (int) floor(
                    ($firstAmountOffset + $secondAmountOffset) / 2,
                );
                $amounts = [];

                foreach ($rowAmountCaptures as [$amount, $amountOffset]) {
                    $physicalCurrency = $amountOffset < $currencyBoundary ? 'PEN' : 'USD';
                    $amounts[$physicalCurrency] = $this->signedMinorUnits($amount);
                }
            } else {
                $isFixtureBackedPenCharge = Str::upper($description) === 'SEGURO DESGRAVAMEN';
                $currency = ! $isFixtureBackedPenCharge
                    && $currencyBoundary !== null
                    && $firstAmountOffset >= $currencyBoundary
                        ? 'USD'
                        : 'PEN';
                $amounts = [$currency => $this->signedMinorUnits($row[4])];
            }
            $nonZeroAmounts = collect($amounts)
                ->reject(fn (string $amount): bool => $amount === '0')
                ->all();

            if (count($nonZeroAmounts) === 0) {
                continue;
            }

            if (count($nonZeroAmounts) > 1) {
                throw $this->invalid('An Interbank row contains amounts in more than one currency.', 'ambiguous_currency_columns');
            }

            $currency = (string) array_key_first($nonZeroAmounts);
            $printedAmount = $nonZeroAmounts[$currency];
            $sectionSums[$section][$currency] = $sectionSums[$section][$currency]
                ->add(ExactInteger::from($printedAmount));
            $position = count($movements) + 1;
            $classification = match ($section) {
                'payments' => Str::upper($description) === 'PAGO TARJ WEB APP'
                    ? StatementMovementClassification::CardPayment
                    : StatementMovementClassification::NeedsClassification,
                default => StatementMovementClassification::Purchase,
            };
            $direction = ExactInteger::from($printedAmount)->compare(ExactInteger::from(0)) === -1
                ? StatementMovementDirection::Credit
                : StatementMovementDirection::Debit;

            $movements[] = new StatementImportPreviewMovement(
                sourceRowId: hash('sha256', "interbank|{$position}|{$line}"),
                position: $position,
                occurredOn: $this->interbankMovementDate(
                    (int) $row[1],
                    $row[2],
                    $periodStart,
                    $periodEnd,
                ),
                description: $description,
                amountMinor: ltrim($printedAmount, '-'),
                currency: Currency::from($currency),
                direction: $direction,
                classification: $classification,
                contributesToSpending: $classification->contributesToSpending(),
                canBeExcluded: false,
                sourceMetadata: [
                    'section' => $section,
                    'printed_amount_minor' => $printedAmount,
                ],
            );
        }

        foreach ([$previous, $printedPayments, $printedConsumption, $printedOtherCharges, $printedPaymentTotal] as $requiredTotals) {
            if ($requiredTotals['PEN'] === null || $requiredTotals['USD'] === null) {
                throw $this->invalid('The Interbank statement totals could not be read.', 'missing_reconciliation');
            }
        }

        foreach (['PEN', 'USD'] as $currency) {
            $paymentsExpected = ExactInteger::from($previous[$currency])
                ->add($sectionSums['payments'][$currency])
                ->value();

            if ($paymentsExpected !== $printedPayments[$currency]) {
                throw $this->invalid("Interbank payments do not reconcile in {$currency}.", 'interbank_payments_mismatch');
            }

            if ($sectionSums['consumption'][$currency]->value() !== $printedConsumption[$currency]) {
                throw $this->invalid("Interbank consumption does not reconcile in {$currency}.", 'interbank_consumption_mismatch');
            }

            if ($sectionSums['other_charges'][$currency]->value() !== $printedOtherCharges[$currency]) {
                throw $this->invalid("Interbank other charges do not reconcile in {$currency}.", 'interbank_other_charges_mismatch');
            }

            $wholeStatement = ExactInteger::from($printedPayments[$currency])
                ->add(ExactInteger::from($printedConsumption[$currency]))
                ->add(ExactInteger::from($printedOtherCharges[$currency]))
                ->value();

            if ($wholeStatement !== $printedPaymentTotal[$currency]) {
                throw $this->invalid("The Interbank statement does not reconcile in {$currency}.", 'interbank_statement_mismatch');
            }
        }

        return new StatementImportPreview(
            financialStatementFormat: FinancialStatementFormat::Interbank,
            parserVersion: 'interbank-v1',
            fileHash: $fileHash,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            instrumentLabel: 'Interbank American Express',
            instrumentLastFour: $this->interbankLastFour($text),
            movements: $movements,
            informationalValues: $informationalValues,
            reconciliation: [
                'previous_balance_pen_minor' => $previous['PEN'],
                'previous_balance_usd_minor' => $previous['USD'],
                'payments_subtotal_pen_minor' => $printedPayments['PEN'],
                'payments_subtotal_usd_minor' => $printedPayments['USD'],
                'consumption_pen_minor' => $printedConsumption['PEN'],
                'consumption_usd_minor' => $printedConsumption['USD'],
                'other_charges_pen_minor' => $printedOtherCharges['PEN'],
                'other_charges_usd_minor' => $printedOtherCharges['USD'],
                'payment_total_pen_minor' => $printedPaymentTotal['PEN'],
                'payment_total_usd_minor' => $printedPaymentTotal['USD'],
            ],
        );
    }

    /** @return array<string, string> */
    private function interbankAmounts(string $line): array
    {
        $line = preg_replace('/-{2,}/u', ' ', $line) ?? $line;
        preg_match_all('/-?[\d,]+\.\d{2}/u', $line, $matches, PREG_OFFSET_CAPTURE);
        $amounts = $matches[0];

        if (count($amounts) === 0) {
            return [];
        }

        if (count($amounts) === 1) {
            return ['PEN' => $this->signedMinorUnits($amounts[0][0])];
        }

        [$penAmount, $usdAmount] = array_slice($amounts, -2);
        $currencyBoundary = (int) floor(($penAmount[1] + $usdAmount[1]) / 2);
        $physicalAmounts = [];

        foreach ([$penAmount, $usdAmount] as [$amount, $amountOffset]) {
            $currency = $amountOffset < $currencyBoundary ? 'PEN' : 'USD';
            $physicalAmounts[$currency] = $this->signedMinorUnits($amount);
        }

        return $physicalAmounts;
    }

    /**
     * @param  array<string, string>  $amounts
     * @return array{PEN: string|null, USD: string|null}
     */
    private function completeCurrencyPair(array $amounts): array
    {
        return [
            'PEN' => $amounts['PEN'] ?? null,
            'USD' => $amounts['USD'] ?? (isset($amounts['PEN']) ? '0' : null),
        ];
    }

    /**
     * @param  array{PEN: string|null, USD: string|null}  $current
     * @param  array{PEN: string|null, USD: string|null}  $additional
     * @return array{PEN: string|null, USD: string|null}
     */
    private function addInterbankCurrencyPairs(array $current, array $additional): array
    {
        return [
            'PEN' => $this->addInterbankCurrencyAmount($current, $additional, 'PEN'),
            'USD' => $this->addInterbankCurrencyAmount($current, $additional, 'USD'),
        ];
    }

    /**
     * @param  array{PEN: string|null, USD: string|null}  $current
     * @param  array{PEN: string|null, USD: string|null}  $additional
     */
    private function addInterbankCurrencyAmount(array $current, array $additional, string $currency): ?string
    {
        if ($additional[$currency] === null) {
            return $current[$currency];
        }

        return ExactInteger::from($current[$currency] ?? 0)
            ->add(ExactInteger::from($additional[$currency]))
            ->value();
    }

    /**
     * @param  array{PEN: string|null, USD: string|null}  $subtotal
     * @param  array{PEN: string|null, USD: string|null}  $expected
     * @return array{PEN: string|null, USD: string|null}
     */
    private function resolveJoinedInterbankSubtotalSign(
        string $line,
        array $subtotal,
        array $expected,
    ): array {
        if (preg_match('/SUBTOTAL(?=[\s-]*-{2,})[\s-]+[\d,]+\.\d{2}/u', $line) !== 1
            || $subtotal['PEN'] === null
            || $expected['PEN'] === null
            || ! str_starts_with($expected['PEN'], '-')
            || ltrim($subtotal['PEN'], '-') !== ltrim($expected['PEN'], '-')) {
            return $subtotal;
        }

        $subtotal['PEN'] = $expected['PEN'];

        return $subtotal;
    }

    private function signedMinorUnits(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $minorUnits = $this->minorUnits(ltrim($amount, '-'));

        return $negative && $minorUnits !== '0' ? '-'.$minorUnits : $minorUnits;
    }

    private function interbankMovementDate(
        int $day,
        string $monthCode,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): CarbonImmutable {
        $months = [
            'ene' => 1,
            'feb' => 2,
            'mar' => 3,
            'abr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'ago' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dic' => 12,
        ];
        $month = $months[Str::lower($monthCode)] ?? 0;
        $year = $month > $periodEnd->month ? $periodStart->year : $periodEnd->year;

        try {
            $occurredOn = CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, config('app.timezone'));
        } catch (Throwable) {
            throw $this->invalid('An Interbank movement contains an invalid date.', 'invalid_movement_date');
        }

        return $occurredOn;
    }

    private function interbankLastFour(string $text): ?string
    {
        if (preg_match('/AMERICAN EXPRESS\s+(\d{4})\b/ui', $text, $instrument) !== 1) {
            return null;
        }

        return $instrument[1];
    }

    /** @param  list<string>  $lines */
    private function bcpDirectionBoundary(array $lines, int $debitColumn, int $creditColumn): int
    {
        $offsets = [];

        foreach ($lines as $line) {
            if (preg_match('/^\d{2}[A-Z]{3}\s+\d{2}[A-Z]{3}\s+/u', $line) !== 1) {
                continue;
            }

            preg_match_all('/[\d,]+\.\d{2}/u', $line, $matches, PREG_OFFSET_CAPTURE);
            $amount = end($matches[0]);

            if (is_array($amount)) {
                $offsets[] = $amount[1];
            }
        }

        sort($offsets);
        $largestGap = 0;
        $fallbackBoundary = (int) floor(($debitColumn + $creditColumn) / 2);
        $boundary = $fallbackBoundary;

        for ($index = 1; $index < count($offsets); $index++) {
            $gap = $offsets[$index] - $offsets[$index - 1];

            if ($gap > $largestGap) {
                $largestGap = $gap;
                $boundary = (int) floor(($offsets[$index] + $offsets[$index - 1]) / 2);
            }
        }

        return $largestGap >= 6 ? $boundary : $fallbackBoundary;
    }

    private function parseNumericDate(string $date): CarbonImmutable
    {
        $format = strlen($date) === 8 ? '!d/m/y' : '!d/m/Y';
        $parsed = CarbonImmutable::createFromFormat($format, $date, config('app.timezone'));

        if ($parsed === null) {
            throw $this->invalid('The statement contains an invalid date.', 'invalid_date');
        }

        return $parsed;
    }

    private function bcpMovementDate(
        int $day,
        string $monthCode,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): CarbonImmutable {
        $months = [
            'ENE' => 1,
            'FEB' => 2,
            'MAR' => 3,
            'ABR' => 4,
            'MAY' => 5,
            'JUN' => 6,
            'JUL' => 7,
            'AGO' => 8,
            'SEP' => 9,
            'OCT' => 10,
            'NOV' => 11,
            'DIC' => 12,
        ];
        $month = $months[$monthCode] ?? 0;
        $year = $month > $periodEnd->month ? $periodStart->year : $periodEnd->year;

        try {
            return CarbonImmutable::createSafe($year, $month, $day, timezone: config('app.timezone'));
        } catch (Throwable) {
            throw $this->invalid('A BCP movement contains an invalid date.', 'invalid_movement_date');
        }
    }

    private function classifyBcp(string $description): StatementMovementClassification
    {
        $normalized = Str::upper($description);

        if ($normalized === 'WARDA') {
            return StatementMovementClassification::Savings;
        }

        if ($normalized === 'IMPUESTO ITF') {
            return StatementMovementClassification::Tax;
        }

        if ($normalized === 'MANT. CUENTA') {
            return StatementMovementClassification::Fee;
        }

        if (Str::startsWith($normalized, [
            'PAGO YAPE A ',
            'PLIN-',
            'IZI*',
            'GOOGLE YOUTUBE',
            'SISTEMAS ORACLE',
            'MFAG83 ',
            'YQ-',
        ])) {
            return StatementMovementClassification::Purchase;
        }

        if (Str::startsWith($normalized, [
            'TRANSF.BCO.INTERBA',
            'TRAN.CTAS.PROP.BM',
            'TRAN.CTAS.TERC.BM',
            'PAGO YAPE DE ',
        ])) {
            return StatementMovementClassification::Transfer;
        }

        return StatementMovementClassification::NeedsClassification;
    }

    /**
     * @param  list<StatementImportPreviewMovement>  $movements
     */
    private function sumMovements(array $movements, StatementMovementDirection $direction): string
    {
        return collect($movements)
            ->filter(fn (StatementImportPreviewMovement $movement): bool => $movement->direction === $direction)
            ->reduce(
                fn (ExactInteger $total, StatementImportPreviewMovement $movement): ExactInteger => $total->add(ExactInteger::from($movement->amountMinor)),
                ExactInteger::from(0),
            )
            ->value();
    }

    private function minorUnits(string $amount): string
    {
        $normalized = str_replace([',', '.'], '', $amount);

        return ExactInteger::from($normalized)->add(ExactInteger::from(0))->value();
    }

    private function bcpLastFour(string $text): ?string
    {
        if (preg_match('/\b\d{3}-\d{7}-\d-\d{2}\b/u', $text, $account) !== 1) {
            return null;
        }

        return substr(str_replace('-', '', $account[0]), -4);
    }

    private function invalid(
        string $message,
        string $errorCode,
        string $validationField = 'statement',
    ): StatementImportValidationException {
        return new StatementImportValidationException($message, $errorCode, $validationField);
    }
}
