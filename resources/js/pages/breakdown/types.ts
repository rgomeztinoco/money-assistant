import type { RecordedCoverageSource } from '@/components/source-coverage';
import type { Currency, IncomeSource, MoneyMovementDetails } from '@/types';

export type BreakdownPeriod = {
    unit: 'week' | 'month' | 'quarter' | 'year' | 'custom';
    label: string;
    anchor: string;
    date_from: string;
    date_to: string;
};

export type CurrencyAmounts = Record<Currency, string>;

export type BreakdownCategory = {
    id: number | null;
    name: string;
};

export type BreakdownCategoryOption = {
    id: number;
    name: string;
    path: string;
    parent: { id: number; name: string } | null;
};

export type BreakdownCategoryGroup = {
    category: BreakdownCategory;
    amount_minor: CurrencyAmounts;
    percentage: CurrencyAmounts;
    children: Array<{
        category: { id: number; name: string };
        amount_minor: CurrencyAmounts;
    }>;
};

export type BreakdownDay = {
    date: string;
    date_to: string;
    net_spending_minor: CurrencyAmounts;
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
    net_spending_minor: CurrencyAmounts;
    income_minor: CurrencyAmounts;
    moved_to_savings_minor: CurrencyAmounts;
    transactions: BreakdownTransaction[];
};

export type BreakdownProps = {
    currency_filter: Currency | null;
    period: BreakdownPeriod;
    coverage: {
        date_from: string | null;
        date_to: string | null;
        transaction_count: number;
        source: RecordedCoverageSource;
    };
    summary: Record<
        Currency,
        {
            net_spending_minor: string;
            income_minor: string;
            moved_to_savings_minor: string;
        }
    >;
    categorization: Record<
        Currency,
        {
            transaction_count: number;
            uncategorized_transaction_count: number;
            uncategorized_amount_minor: string;
            uncategorized_percentage: string;
        }
    >;
    filters: {
        category: string | null;
        day: string | null;
        focus: 'net_spending' | 'income' | 'savings' | null;
        merchant: string | null;
        attention: boolean;
        selected: number | null;
    };
    category_groups: BreakdownCategoryGroup[];
    chart_granularity: 'day' | 'week' | 'month';
    days: BreakdownDay[];
    merchants: Array<{
        name: string;
        amount_minor: CurrencyAmounts;
        transaction_count: number;
    }>;
    transaction_days: BreakdownTransactionDay[];
    category_options: BreakdownCategoryOption[];
    income_source_options: Array<{
        value: IncomeSource;
        used: boolean;
    }>;
    today: string;
};
