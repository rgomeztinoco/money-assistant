// THROWAWAY: deterministic sample data for the Trends design exploration. Never persisted.
import { addMonths, endOfMonth, format, parseISO } from 'date-fns';
import type { Currency } from '@/types';
import type { Finding, Period, TrendsProps } from './index';

export const sampleMonths = [
    '2026-04',
    '2026-05',
    '2026-06',
    '2026-07',
    '2026-08',
    '2026-09',
];

export function sampleTrends(month: string, currency: Currency): TrendsProps {
    const anchor = parseISO(`${month}-01`);
    const monthIndex = sampleMonths.indexOf(month);
    const partial = month === '2026-09';
    const scale = currency === 'USD' ? 0.24 : 1;
    const seasonal = [0.8, 0.9, 1, 1.16, 0.86, 1.08][monthIndex];
    const amount = (value: number) =>
        String(Math.round(value * scale * seasonal));
    function period(offset: number, equivalent: boolean): Period {
        const start = addMonths(anchor, offset);
        const end =
            equivalent && partial
                ? new Date(start.getFullYear(), start.getMonth(), 10)
                : endOfMonth(start);

        return {
            label: `${format(start, 'MMM d')} to ${format(end, 'MMM d, yyyy')}`,
            date_from: format(start, 'yyyy-MM-dd'),
            date_to: format(end, 'yyyy-MM-dd'),
        };
    }
    const seeds = [
        {
            name: 'Shopping',
            kind: 'category',
            current: 98000,
            typical: 38000,
            count: 5,
            usualCount: 3,
            unusual: 'Home office chair',
            unusualAmount: 54000,
        },
        {
            name: 'Dining out',
            kind: 'category',
            current: 64800,
            typical: 39600,
            count: 14,
            usualCount: 9,
        },
        {
            name: 'La Bodega',
            kind: 'merchant',
            current: 31800,
            typical: 14400,
            count: 7,
            usualCount: 3,
        },
        {
            name: 'Transport',
            kind: 'category',
            current: 12600,
            typical: 24800,
            count: 8,
            usualCount: 15,
        },
        {
            name: 'Groceries',
            kind: 'category',
            current: 45200,
            typical: 53600,
            count: 6,
            usualCount: 8,
        },
        {
            name: 'Uber',
            kind: 'merchant',
            current: 9400,
            typical: 16400,
            count: 6,
            usualCount: 11,
        },
    ] as const;
    const findings: Finding[] = seeds
        .map((seed, index): Finding => {
            const current =
                monthIndex === 4 && index === 0 ? 26000 : seed.current;
            const currentAmount = amount(current);
            const typicalAmount = amount(seed.typical);
            const change = Number(currentAmount) - Number(typicalAmount);
            const base = {
                currency,
                current_total_minor: currentAmount,
                typical_total_minor: typicalAmount,
                change_minor: String(change),
                current_transaction_count: seed.count,
                typical_transaction_count: seed.usualCount,
                unusual_transaction:
                    'unusual' in seed && monthIndex !== 4
                        ? {
                              id: -1,
                              description: seed.unusual,
                              amount_minor: amount(seed.unusualAmount),
                          }
                        : null,
                scenario:
                    change > 0 ? { difference_minor: String(change) } : null,
            };

            return seed.kind === 'category'
                ? {
                      ...base,
                      kind: 'category',
                      category: { id: index + 1, name: seed.name },
                  }
                : { ...base, kind: 'merchant', merchant: seed.name };
        })
        .sort(
            (a, b) =>
                Math.abs(Number(b.change_minor)) -
                Math.abs(Number(a.change_minor)),
        );

    return {
        currency,
        available_currencies: ['PEN', 'USD'],
        period: period(0, true),
        comparison_periods: [-1, -2, -3].map((offset) => period(offset, true)),
        summary: {
            net_spending_minor: amount(326800),
            income_minor: amount(680000),
            moved_to_savings_minor: amount(85000),
        },
        findings,
        monthly_context: [-5, -4, -3, -2, -1, 0].map((offset, index) => ({
            ...period(offset, offset === 0),
            month: format(addMonths(anchor, offset), 'yyyy-MM'),
            total_minor: amount(
                offset === 0
                    ? 326800
                    : [432800, 486000, 415200, 598000, 458400][index],
            ),
        })),
    };
}
