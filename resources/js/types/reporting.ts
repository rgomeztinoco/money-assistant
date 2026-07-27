import type { Currency } from './ledger';

export type DailyExchangeRate = {
    id: number;
    applicable_on: string;
    pen_per_usd: string;
    pen_per_usd_scaled: string;
    revision: number;
    owner_managed: boolean;
};

export type CombinedTotal = {
    currency: Currency | null;
    amount_minor: string | null;
    unavailable_reason:
        'reporting_currency_not_selected' | 'missing_exchange_rates' | null;
    missing_rate_dates: string[];
};
