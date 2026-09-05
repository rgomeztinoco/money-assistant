<?php

namespace App\StatementImports\Formats;

use App\Contracts\StatementImports\FinancialStatementFormatAdapter;
use App\Currency;
use App\ExactInteger;
use App\FinancialStatementFormat;
use App\MovementDirection;
use App\StatementImports\StatementImportPreview;
use App\StatementImports\StatementImportPreviewMovement;
use App\StatementImports\StatementImportValidationException;
use App\StatementMovementClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class BcpFinancialStatementAdapter implements FinancialStatementFormatAdapter
{
    public function matches(string $statementText): bool
    {
        return Str::containsAll($statementText, [
            'Estado de Cuenta de Ahorros',
            'CARGOS / DEBE',
            'ABONOS / HABER',
        ]);
    }

    public function preview(string $statementText, string $fileHash): StatementImportPreview
    {
        if (preg_match('/DEL\s+(\d{2}\/\d{2}\/\d{2,4})\s+AL\s+(\d{2}\/\d{2}\/\d{2,4})/u', $statementText, $period) !== 1) {
            throw $this->invalid('The BCP statement period could not be read.', 'invalid_period');
        }

        $periodStart = $this->parseNumericDate($period[1]);
        $periodEnd = $this->parseNumericDate($period[2]);
        $lines = preg_split('/\R/u', $statementText) ?: [];
        $debitColumn = null;
        $creditColumn = null;
        $directionBoundary = null;
        $openingBalance = null;
        $printedDebits = null;
        $printedCredits = null;
        $closingBalance = null;
        $totalsFound = false;
        $movements = [];

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

            $directionBoundary ??= $this->directionBoundary($lines, $debitColumn, $creditColumn);
            $direction = $amountOffset >= $directionBoundary
                ? MovementDirection::Credit
                : MovementDirection::Debit;
            $position = count($movements) + 1;
            $occurredOn = $this->movementDate(
                (int) $dateMatch[1],
                $dateMatch[2],
                $periodStart,
                $periodEnd,
            );
            $canBeExcluded = $amountMinor === '0';
            $classification = $canBeExcluded
                ? StatementMovementClassification::NotAMovement
                : $this->classification($description, $direction);

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

        $parsedDebits = $this->sumMovements($movements, MovementDirection::Debit);
        $parsedCredits = $this->sumMovements($movements, MovementDirection::Credit);
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
            parserVersion: 'bcp-v2',
            fileHash: $fileHash,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            instrumentLabel: 'BCP Cuenta Digital',
            instrumentLastFour: $this->lastFour($statementText),
            movements: $movements,
            informationalValues: [],
            reconciliation: [
                'opening_balance_minor' => $openingBalance,
                'debits_minor' => $printedDebits,
                'credits_minor' => $printedCredits,
                'closing_balance_minor' => $closingBalance,
            ],
        );
    }

    /** @param list<string> $lines */
    private function directionBoundary(array $lines, int $debitColumn, int $creditColumn): int
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

    private function movementDate(
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

    private function classification(
        string $description,
        MovementDirection $direction,
    ): StatementMovementClassification {
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

        if (Str::startsWith($normalized, 'DE TK BUSINESS ONL')) {
            return StatementMovementClassification::Income;
        }

        if (Str::startsWith($normalized, 'TRANSF.BCO.FINANCI')) {
            return StatementMovementClassification::Savings;
        }

        if (Str::startsWith($normalized, [
            'TRANSF.BCO.INTERBA',
            'TRANSF.BCO.BBVA',
            'TRAN.CTAS.PROP.BM',
            'RETIRO EFECTIVO',
        ])) {
            return StatementMovementClassification::Transfer;
        }

        if (Str::contains($normalized, 'YAPE') || Str::startsWith($normalized, 'YQ-')) {
            return $this->thirdPartyClassification($direction);
        }

        if (Str::startsWith($normalized, [
            'PLIN-',
            'ABON PLIN-',
            'EXT PLIN-',
            'IZI*',
            'GOOGLE YOUTUBE',
            'SISTEMAS ORACLE',
            'EXT SISTEMAS ORACL',
            'MFAG83 ',
            'TRAN.CEL.BM.',
            'TRAN.CTAS.TERC.BM',
        ])) {
            return $this->thirdPartyClassification($direction);
        }

        return StatementMovementClassification::NeedsClassification;
    }

    private function thirdPartyClassification(
        MovementDirection $direction,
    ): StatementMovementClassification {
        return $direction === MovementDirection::Debit
            ? StatementMovementClassification::Purchase
            : StatementMovementClassification::Refund;
    }

    /** @param list<StatementImportPreviewMovement> $movements */
    private function sumMovements(array $movements, MovementDirection $direction): string
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

    private function lastFour(string $statementText): ?string
    {
        if (preg_match('/\b\d{3}-\d{7}-\d-\d{2}\b/u', $statementText, $account) !== 1) {
            return null;
        }

        return substr(str_replace('-', '', $account[0]), -4);
    }

    private function invalid(string $message, string $errorCode): StatementImportValidationException
    {
        return new StatementImportValidationException($message, $errorCode);
    }
}
