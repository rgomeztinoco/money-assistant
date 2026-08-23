import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency } from '@/types';

type TransactionFilterPeriod = {
    date_from: string;
    date_to: string;
};

export function periodBreakdownUrl({
    currency,
    period,
}: {
    currency: Currency;
    period: TransactionFilterPeriod;
}) {
    return breakdownIndex({
        query: {
            currency,
            preset: 'custom',
            date_from: period.date_from,
            date_to: period.date_to,
        },
    });
}

export function categoryBreakdownUrl({
    currency,
    period,
    categoryId,
}: {
    currency: Currency;
    period: TransactionFilterPeriod;
    categoryId: number | null;
}) {
    return breakdownIndex({
        query: {
            currency,
            preset: 'custom',
            date_from: period.date_from,
            date_to: period.date_to,
            category:
                categoryId === null ? 'uncategorized' : categoryId.toString(),
        },
    });
}
