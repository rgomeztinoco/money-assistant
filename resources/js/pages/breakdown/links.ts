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
    selected,
}: {
    currency: Currency;
    period: BreakdownPeriod;
    category: string | null;
    day: string | null;
    selected: number | null;
}) {
    return breakdownIndex({
        query: {
            ...periodQuery({ currency, period }),
            category: category ?? undefined,
            day: day ?? undefined,
            selected: selected ?? undefined,
        },
    });
}
