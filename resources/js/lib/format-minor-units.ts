import type { Currency } from '@/types';

function normalizedMinorUnits(amountMinor: string) {
    const isNegative = amountMinor.startsWith('-');

    return {
        isNegative,
        digits: (isNegative ? amountMinor.slice(1) : amountMinor).padStart(
            3,
            '0',
        ),
    };
}

export function formatMinorUnits(
    amountMinor: string,
    currency: Currency,
): string {
    const { isNegative, digits } = normalizedMinorUnits(amountMinor);
    const integerPart = digits
        .slice(0, -2)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const symbol = currency === 'USD' ? '$' : 'S/';

    return `${isNegative ? '−' : ''}${symbol} ${integerPart}.${digits.slice(-2)}`;
}

export function minorUnitsToCurrencyUnits(amountMinor: string): string {
    const { isNegative, digits } = normalizedMinorUnits(amountMinor);

    return `${isNegative ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

export function currencyUnitsToMinorUnits(amount: string): bigint | null {
    const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(amount);

    if (match === null) {
        return null;
    }

    const sign = match[1] === '-' ? -1n : 1n;
    const fraction = (match[3] ?? '').padEnd(2, '0');

    return sign * BigInt(`${match[2]}${fraction}`);
}
