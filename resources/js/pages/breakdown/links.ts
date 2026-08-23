import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency } from '@/types';
import type { BreakdownPeriod } from './types';

export function periodQuery({
    currency,
    period,
}: {
    currency: Currency;
    period: BreakdownPeriod;
}) {
    return {
        currency,
        preset: period.preset === 'latest_month' ? undefined : period.preset,
        date_from: period.preset === 'custom' ? period.date_from : undefined,
        date_to: period.preset === 'custom' ? period.date_to : undefined,
    };
}

export function selectionUrl({
    currency,
    period,
    category,
    day,
    focus,
    merchant,
    attention,
    selected,
}: {
    currency: Currency;
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
            ...periodQuery({ currency, period }),
            category: category ?? undefined,
            day: day ?? undefined,
            focus: focus ?? undefined,
            merchant: merchant ?? undefined,
            attention: attention ? 1 : undefined,
            selected: selected ?? undefined,
        },
    });
}
