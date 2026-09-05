import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency } from '@/types';

type TransactionFilterPeriod = {
    date_from: string;
    date_to: string;
};

export function periodBreakdownUrl({
    currency,
    period,
    focus,
    merchant,
    attention,
    selected,
}: {
    currency: Currency;
    period: TransactionFilterPeriod;
    focus?: 'net_spending' | 'income' | 'savings';
    merchant?: string;
    attention?: boolean;
    selected?: number;
}) {
    return breakdownIndex({
        query: {
            currency,
            preset: 'custom',
            date_from: period.date_from,
            date_to: period.date_to,
            focus,
            merchant,
            attention: attention ? 1 : undefined,
            selected,
        },
    });
}

export function categoryBreakdownUrl({
    currency,
    period,
    categoryId,
    selected,
}: {
    currency: Currency;
    period: TransactionFilterPeriod;
    categoryId: number | null;
    selected?: number;
}) {
    return breakdownIndex({
        query: {
            currency,
            preset: 'custom',
            date_from: period.date_from,
            date_to: period.date_to,
            category:
                categoryId === null ? 'uncategorized' : categoryId.toString(),
            selected,
        },
    });
}
