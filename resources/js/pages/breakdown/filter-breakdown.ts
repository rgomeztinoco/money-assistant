import type { Currency } from '@/types';
import type {
    BreakdownProps,
    BreakdownTransaction,
    CurrencyAmounts,
} from './types';

function normalizeMerchant(description: string): string {
    return description
        .normalize('NFKC')
        .toLowerCase()
        .replace(/\p{P}+/gu, ' ')
        .replace(/\s+/gu, ' ')
        .trim();
}

function supportsCategory(transaction: BreakdownTransaction): boolean {
    return transaction.kind === 'spending' || transaction.kind === 'refund';
}

function netSpending(transaction: BreakdownTransaction): bigint {
    return transaction.kind === 'spending'
        ? BigInt(transaction.amount_minor)
        : transaction.kind === 'refund'
          ? -BigInt(transaction.amount_minor)
          : 0n;
}

function matchesCategory(
    transaction: BreakdownTransaction,
    category: string | null,
): boolean {
    if (category === null) {
        return true;
    }

    if (!supportsCategory(transaction)) {
        return false;
    }

    const categories = transaction.split?.length
        ? transaction.split.map((item) => item.category)
        : [transaction.category];

    if (category === 'uncategorized') {
        return categories.includes(null);
    }

    const directOnly = category.startsWith('direct:');
    const categoryId = Number(category.replace(/^direct:/, ''));

    return categories.some(
        (contribution) =>
            contribution?.id === categoryId ||
            (!directOnly && contribution?.parent_id === categoryId),
    );
}

function matchesFocus(
    transaction: BreakdownTransaction,
    focus: BreakdownProps['filters']['focus'],
): boolean {
    return (
        focus === null ||
        (focus === 'net_spending' && supportsCategory(transaction)) ||
        (focus === 'income' && transaction.kind === 'income') ||
        (focus === 'savings' &&
            transaction.kind === 'transfer' &&
            transaction.transfer_purpose === 'savings')
    );
}

function amounts(
    transactions: BreakdownTransaction[],
    contribution: (transaction: BreakdownTransaction) => bigint,
): CurrencyAmounts {
    const totals = { PEN: 0n, USD: 0n };

    for (const transaction of transactions) {
        totals[transaction.currency] += contribution(transaction);
    }

    return { PEN: totals.PEN.toString(), USD: totals.USD.toString() };
}

export function filterBreakdown(props: BreakdownProps): BreakdownProps {
    const { filters } = props;
    const attentionIds = new Set(props.attention_transaction_ids);
    const transactions = props.transaction_days.flatMap(
        (day) => day.transactions,
    );
    const categoryTransactions = transactions.filter((transaction) =>
        matchesCategory(transaction, filters.category),
    );
    const merchantTransactions = categoryTransactions.filter(
        (transaction) =>
            (filters.day === null || transaction.occurred_on === filters.day) &&
            matchesFocus(transaction, filters.focus) &&
            (!filters.attention || attentionIds.has(transaction.id)),
    );
    const merchantKey =
        filters.merchant === null ? null : normalizeMerchant(filters.merchant);
    const detailTransactions = new Set(
        merchantTransactions
            .filter(
                (transaction) =>
                    merchantKey === null ||
                    normalizeMerchant(transaction.description) === merchantKey,
            )
            .map((transaction) => transaction.id),
    );
    const merchants = new Map<
        string,
        {
            name: string;
            amount: Record<Currency, bigint>;
            transaction_count: number;
        }
    >();

    for (const transaction of merchantTransactions) {
        if (!supportsCategory(transaction)) {
            continue;
        }

        const key = normalizeMerchant(transaction.description);
        const merchant = merchants.get(key) ?? {
            name: transaction.description,
            amount: { PEN: 0n, USD: 0n },
            transaction_count: 0,
        };
        merchant.amount[transaction.currency] += netSpending(transaction);
        merchant.transaction_count++;
        merchants.set(key, merchant);
    }

    return {
        ...props,
        category_groups:
            filters.day === null
                ? props.category_groups
                : (props.category_groups_by_day[filters.day] ?? []),
        days: props.days.map((bucket) => {
            const bucketTransactions = categoryTransactions.filter(
                (transaction) =>
                    transaction.occurred_on >= bucket.date &&
                    transaction.occurred_on <= bucket.date_to,
            );

            return {
                ...bucket,
                net_spending_minor: amounts(bucketTransactions, netSpending),
                transaction_count: bucketTransactions.length,
            };
        }),
        merchants: Array.from(merchants.values())
            .map((merchant) => ({
                name: merchant.name,
                amount_minor: {
                    PEN: merchant.amount.PEN.toString(),
                    USD: merchant.amount.USD.toString(),
                },
                transaction_count: merchant.transaction_count,
            }))
            .sort(
                (left, right) =>
                    right.transaction_count - left.transaction_count,
            ),
        transaction_days: props.transaction_days
            .map((day) => {
                const selected = day.transactions.filter((transaction) =>
                    detailTransactions.has(transaction.id),
                );

                return {
                    ...day,
                    transactions: selected,
                    net_spending_minor: amounts(selected, netSpending),
                    income_minor: amounts(selected, (transaction) =>
                        transaction.kind === 'income'
                            ? BigInt(transaction.amount_minor)
                            : 0n,
                    ),
                    moved_to_savings_minor: amounts(selected, (transaction) =>
                        transaction.kind === 'transfer' &&
                        transaction.transfer_purpose === 'savings'
                            ? BigInt(transaction.amount_minor) *
                              (transaction.direction === 'credit' ? -1n : 1n)
                            : 0n,
                    ),
                };
            })
            .filter((day) => day.transactions.length > 0),
    };
}
