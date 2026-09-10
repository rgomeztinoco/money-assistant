import type { Currency } from '@/types';

export type Period = {
    label: string;
    date_from: string;
    date_to: string;
};

export type Summary = {
    net_spending_minor: string;
    income_minor: string;
    moved_to_savings_minor: string;
};

type FindingBase = {
    currency: Currency;
    current_total_minor: string;
    typical_total_minor: string;
    change_minor: string;
    current_transaction_count: number;
    typical_transaction_count: number;
    unusual_transaction: {
        id: number;
        description: string;
        amount_minor: string;
    } | null;
    scenario: { difference_minor: string } | null;
};

export type CategoryFinding = FindingBase & {
    kind: 'category';
    category: { id: number | null; name: string };
};

export type MerchantFinding = FindingBase & {
    kind: 'merchant';
    merchant: string;
};

export type Finding = CategoryFinding | MerchantFinding;

export type MonthlyContext = Period & {
    month: string;
    total_minor: string | null;
};

export type TrendsProps = {
    currency: Currency;
    available_currencies: Currency[];
    period: Period;
    comparison_periods: Period[];
    summary: Summary | null;
    findings: Finding[];
    monthly_context: MonthlyContext[];
};
