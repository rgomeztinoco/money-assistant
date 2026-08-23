import type {
    CategoryOption,
    Currency,
    IncomeSource,
    MoneyMovementDetails,
} from '@/types';

export type BreakdownPeriod = {
    preset:
        'this_month' | 'last_month' | 'rolling_30' | 'custom' | 'latest_month';
    label: string;
    date_from: string;
    date_to: string;
};

export type BreakdownCategory = {
    id: number | null;
    name: string;
};

export type BreakdownCategoryGroup = {
    category: BreakdownCategory;
    amount_minor: string;
    percentage: string;
    children: Array<{
        category: { id: number; name: string };
        amount_minor: string;
    }>;
};

export type BreakdownDay = {
    date: string;
    net_spending_minor: string;
    transaction_count: number;
};

type BreakdownTransactionBase = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    description: string;
    category: { id: number; name: string } | null;
    original_spending_id: number | null;
    merchant_match_count: number;
    instrument_label: string | null;
    instrument_last_four: string | null;
    confirmed_at: string;
    statement_import_id: number | null;
    split: Array<{
        id: string;
        amount_minor: string;
        category: { id: number; name: string } | null;
    }> | null;
};

export type BreakdownTransaction = BreakdownTransactionBase &
    MoneyMovementDetails;

export type BreakdownTransactionDay = {
    date: string;
    net_spending_minor: string;
    income_minor: string;
    moved_to_savings_minor: string;
    transactions: BreakdownTransaction[];
};

export type BreakdownProps = {
    currency: Currency;
    period: BreakdownPeriod;
    coverage: {
        date_from: string | null;
        date_to: string | null;
        transaction_count: number;
    };
    summary: {
        net_spending_minor: string;
        income_minor: string;
        moved_to_savings_minor: string;
    };
    filters: {
        category: string | null;
        day: string | null;
        focus: 'net_spending' | 'income' | 'savings' | null;
        merchant: string | null;
        attention: boolean;
        selected: number | null;
    };
    category_groups: BreakdownCategoryGroup[];
    days: BreakdownDay[];
    merchants: Array<{
        name: string;
        amount_minor: string;
        transaction_count: number;
    }>;
    transaction_days: BreakdownTransactionDay[];
    category_options: Array<CategoryOption & { used: boolean }>;
    income_source_options: Array<{
        value: IncomeSource;
        used: boolean;
    }>;
    today: string;
};
