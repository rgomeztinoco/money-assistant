import type { Currency, TransactionKind } from './ledger';

export type LearnedRuleMatchMode = 'exact' | 'starts_with' | 'contains';

export type LearnedRuleDefinition = {
    category_id: number;
    category_name: string;
    merchant_pattern: string;
    merchant_key: string;
    match_mode: LearnedRuleMatchMode;
    transaction_kind: TransactionKind | null;
    currency: Currency | null;
    payment_instrument_label: string | null;
    payment_instrument_last_four: string | null;
};

export type LearnedRule = LearnedRuleDefinition & {
    id: number;
    revision: number;
    activated_at: string;
};

export type LearnedRuleSuggestion = LearnedRuleDefinition & {
    id: number;
    evidence_count: number;
};

export type LearnedRuleCandidate = LearnedRuleDefinition & {
    transaction_id: number;
    transaction_revision: number;
};
