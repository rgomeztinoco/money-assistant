import type { Currency } from '@/types';

export function formatMinorUnits(
    amountMinor: string,
    currency: Currency,
): string {
    const isNegative = amountMinor.startsWith('-');
    const digits = (isNegative ? amountMinor.slice(1) : amountMinor).padStart(
        3,
        '0',
    );
    const integerPart = digits
        .slice(0, -2)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const symbol = currency === 'USD' ? '$' : 'S/';

    return `${isNegative ? '−' : ''}${symbol} ${integerPart}.${digits.slice(-2)}`;
}
