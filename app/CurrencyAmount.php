<?php

namespace App;

use InvalidArgumentException;

final readonly class CurrencyAmount
{
    public static function minorUnits(string $amount, Currency $currency): int
    {
        $decimalPlaces = match ($currency) {
            Currency::Usd, Currency::Pen => 2,
        };
        $pattern = '/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d{1,'.$decimalPlaces.'}))?$/D';

        if (preg_match($pattern, $amount, $parts) !== 1) {
            throw new InvalidArgumentException('The amount must use currency units with at most two decimal places.');
        }

        $fraction = str_pad($parts['fraction'] ?? '', $decimalPlaces, '0');
        $absoluteMinorUnits = ltrim($parts['whole'].$fraction, '0');
        $absoluteMinorUnits = $absoluteMinorUnits === '' ? '0' : $absoluteMinorUnits;

        if (ExactInteger::from($absoluteMinorUnits)->compare(ExactInteger::from(PHP_INT_MAX)) === 1) {
            throw new InvalidArgumentException('The amount is too large.');
        }

        $minorUnits = (int) $absoluteMinorUnits;

        return $parts['sign'] === '-' ? -$minorUnits : $minorUnits;
    }

    public static function currencyUnits(int|string $minorUnits, Currency $currency): string
    {
        $decimalPlaces = match ($currency) {
            Currency::Usd, Currency::Pen => 2,
        };
        $minorUnits = ExactInteger::from($minorUnits)->value();
        $isNegative = str_starts_with($minorUnits, '-');
        $digits = str_pad($isNegative ? mb_substr($minorUnits, 1) : $minorUnits, $decimalPlaces + 1, '0', STR_PAD_LEFT);

        return ($isNegative ? '-' : '')
            .mb_substr($digits, 0, -$decimalPlaces)
            .'.'
            .mb_substr($digits, -$decimalPlaces);
    }
}
