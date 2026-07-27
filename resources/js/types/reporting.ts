import type { Currency } from './ledger';

export type DailyExchangeRate = {
    id: number;
    applicable_on: string;
    pen_per_usd: string;
    pen_per_usd_scaled: string;
    revision: number;
    owner_managed: boolean;
    source: {
        label: 'BCRP interbank sell';
        attribution: 'Banco Central de Reserva del Peru';
        series: 'PD04638PD';
        observed_on: string;
        retrieved_at: string;
        value: string;
        precision: number;
    } | null;
};

export type DailyExchangeRateSeedRequest = {
    id: number;
    applicable_on: string;
    state: 'pending' | 'owner_entry_required' | 'retrieval_failed';
    attempt_count: number;
    next_attempt_at: string | null;
};

export type CombinedTotal = {
    currency: Currency | null;
    amount_minor: string | null;
    unavailable_reason:
        'reporting_currency_not_selected' | 'missing_exchange_rates' | null;
    missing_rate_dates: string[];
};
