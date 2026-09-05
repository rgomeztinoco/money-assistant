import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency } from '@/types';
import type { BreakdownPeriod } from './types';

export function periodQuery({
    currencyFilter,
    period,
}: {
    currencyFilter: Currency | null;
    period: BreakdownPeriod;
}) {
    return {
        currency: currencyFilter ?? undefined,
        period: period.unit,
        anchor: period.unit === 'custom' ? undefined : period.anchor,
        date_from: period.unit === 'custom' ? period.date_from : undefined,
        date_to: period.unit === 'custom' ? period.date_to : undefined,
    };
}

export function selectionUrl({
    currencyFilter,
    period,
    category,
    day,
    focus,
    merchant,
    attention,
    selected,
}: {
    currencyFilter: Currency | null;
    period: BreakdownPeriod;
    category: string | null;
    day: string | null;
    focus?: 'net_spending' | 'income' | 'savings' | null;
    merchant?: string | null;
    attention?: boolean;
    selected: number | null;
}) {
    return breakdownIndex({
        query: {
            ...periodQuery({ currencyFilter, period }),
            category: category ?? undefined,
            day: day ?? undefined,
            focus: focus ?? undefined,
            merchant: merchant ?? undefined,
            attention: attention ? 1 : undefined,
            selected: selected ?? undefined,
        },
    });
}
