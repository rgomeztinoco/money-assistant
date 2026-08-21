import { index as transactionsIndex } from '@/routes/transactions';
import type { Currency } from '@/types';

type TransactionFilterPeriod = {
    date_from: string;
    date_to: string;
};

export function periodTransactionsUrl({
    currency,
    period,
}: {
    currency: Currency;
    period: TransactionFilterPeriod;
}) {
    return transactionsIndex({
        query: {
            currency,
            date_from: period.date_from,
            date_to: period.date_to,
        },
    });
}

export function categoryTransactionsUrl({
    currency,
    period,
    categoryId,
}: {
    currency: Currency;
    period: TransactionFilterPeriod;
    categoryId: number | null;
}) {
    return transactionsIndex({
        query: {
            currency,
            date_from: period.date_from,
            date_to: period.date_to,
            ...(categoryId === null
                ? { category_state: 'uncategorized' }
                : { category_id: categoryId }),
        },
    });
}
