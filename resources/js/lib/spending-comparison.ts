import { formatMinorUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';

export type SpendingComparison = {
    current_total_minor: string;
    previous_total_minor: string;
    change_minor: string;
    percentage_change: string | null;
    direction:
        'increased' | 'decreased' | 'unchanged' | 'no_baseline' | 'no_activity';
};

function absoluteValue(value: string) {
    return value.startsWith('-') ? value.slice(1) : value;
}

export function spendingComparisonDescription({
    comparison,
    currency,
}: {
    comparison: SpendingComparison;
    currency: Currency;
}) {
    switch (comparison.direction) {
        case 'increased':
            return `Up ${formatMinorUnits(absoluteValue(comparison.change_minor), currency)} · ${absoluteValue(comparison.percentage_change ?? '0')}% more than the previous period`;
        case 'decreased':
            return `Down ${formatMinorUnits(absoluteValue(comparison.change_minor), currency)} · ${absoluteValue(comparison.percentage_change ?? '0')}% less than the previous period`;
        case 'unchanged':
            return 'No amount change from the previous period';
        case 'no_baseline':
            return `${formatMinorUnits(comparison.change_minor, currency)} change; no comparable spending in the previous period`;
        case 'no_activity':
            return 'No spending in either period';
        default: {
            const _exhaustive: never = comparison.direction;

            return _exhaustive;
        }
    }
}
