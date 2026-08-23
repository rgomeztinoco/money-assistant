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

final class InterbankFinancialStatementAdapter implements FinancialStatementFormatAdapter
{
    public function matches(string $statementText): bool
    {
        return Str::containsAll($statementText, [
            'DETALLE DE PAGO DEL MES',
            'TUS CONSUMOS',
            'PAGO DEL MES',
        ]);
    }

    public function preview(string $statementText, string $fileHash): StatementImportPreview
    {
        if (preg_match('/Tarjeta de Cr(?:é|e)dito del\s+(\d{2}\/\d{2}\/\d{4})\s+al cierre de\s+(\d{2}\/\d{2}\/\d{4})/ui', $statementText, $period) !== 1) {
            throw $this->invalid('The Interbank statement period could not be read.', 'invalid_period');
        }

        $periodStart = $this->parseNumericDate($period[1]);
        $periodEnd = $this->parseNumericDate($period[2]);
        $lines = preg_split('/\R/u', $statementText) ?: [];
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
                $previous = $this->completeCurrencyPair($this->amounts($line));

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
                $minimumPayment = $this->completeCurrencyPair($this->amounts($line));

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
                $printedPaymentTotal = $this->completeCurrencyPair($this->amounts($line));
                $section = null;

                continue;
            }

            if (Str::startsWith($normalizedLine, 'SUBTOTAL')) {
                $subtotal = $this->completeCurrencyPair($this->amounts($line));
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
                $subtotal = $this->resolveJoinedSubtotalSign($line, $subtotal, $expectedSubtotal);

                match ($section) {
                    'payments' => $printedPayments = $subtotal,
                    'consumption' => $printedConsumption = $this->addCurrencyPairs($printedConsumption, $subtotal),
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
                $currencyBoundary = (int) floor(($firstAmountOffset + $secondAmountOffset) / 2);
                $rowAmounts = [];

                foreach ($rowAmountCaptures as [$amount, $amountOffset]) {
                    $physicalCurrency = $amountOffset < $currencyBoundary ? 'PEN' : 'USD';
                    $rowAmounts[$physicalCurrency] = $this->signedMinorUnits($amount);
                }
            } else {
                $isFixtureBackedPenCharge = Str::upper($description) === 'SEGURO DESGRAVAMEN';
                $currency = ! $isFixtureBackedPenCharge
                    && $currencyBoundary !== null
                    && $firstAmountOffset >= $currencyBoundary
                        ? 'USD'
                        : 'PEN';
                $rowAmounts = [$currency => $this->signedMinorUnits($row[4])];
            }
            $nonZeroAmounts = collect($rowAmounts)
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
                ? MovementDirection::Credit
                : MovementDirection::Debit;

            $movements[] = new StatementImportPreviewMovement(
                sourceRowId: hash('sha256', "interbank|{$position}|{$line}"),
                position: $position,
                occurredOn: $this->movementDate((int) $row[1], $row[2], $periodStart, $periodEnd),
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
            instrumentLastFour: $this->lastFour($statementText),
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
    private function amounts(string $line): array
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
    private function addCurrencyPairs(array $current, array $additional): array
    {
        return [
            'PEN' => $this->addCurrencyAmount($current, $additional, 'PEN'),
            'USD' => $this->addCurrencyAmount($current, $additional, 'USD'),
        ];
    }

    /**
     * @param  array{PEN: string|null, USD: string|null}  $current
     * @param  array{PEN: string|null, USD: string|null}  $additional
     */
    private function addCurrencyAmount(array $current, array $additional, string $currency): ?string
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
    private function resolveJoinedSubtotalSign(string $line, array $subtotal, array $expected): array
    {
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

    private function movementDate(
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
            return CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, config('app.timezone'));
        } catch (Throwable) {
            throw $this->invalid('An Interbank movement contains an invalid date.', 'invalid_movement_date');
        }
    }

    private function parseNumericDate(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!d/m/Y', $date, config('app.timezone'));

        if ($parsed === null) {
            throw $this->invalid('The statement contains an invalid date.', 'invalid_date');
        }

        return $parsed;
    }

    private function minorUnits(string $amount): string
    {
        $normalized = str_replace([',', '.'], '', $amount);

        return ExactInteger::from($normalized)->add(ExactInteger::from(0))->value();
    }

    private function lastFour(string $statementText): ?string
    {
        if (preg_match('/AMERICAN EXPRESS\s+(\d{4})\b/ui', $statementText, $instrument) !== 1) {
            return null;
        }

        return $instrument[1];
    }

    private function invalid(string $message, string $errorCode): StatementImportValidationException
    {
        return new StatementImportValidationException($message, $errorCode);
    }
}
