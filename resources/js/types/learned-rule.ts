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
    retired_at: string | null;
};

export type LearnedRuleSuggestion = LearnedRuleDefinition & {
    id: number;
    evidence_count: number;
};

export type LearnedRuleCandidate = LearnedRuleDefinition & {
    transaction_id: number;
    transaction_revision: number;
};

export type LearnedRuleChangePreview = {
    id: number;
    learned_rule_id: number | null;
    definition: LearnedRuleDefinition;
    existing_match_count: number;
    existing_matches: {
        id: number;
        revision: number;
        merchant_description: string;
        category_name: string | null;
    }[];
    new_match_count: number;
    new_matches: {
        id: number;
        revision: number;
        merchant_description: string;
        category_name: string | null;
    }[];
    lost_match_count: number;
    lost_matches: {
        id: number;
        revision: number;
        merchant_description: string;
        category_name: string | null;
    }[];
    overlaps: {
        rule_id: number;
        revision: number;
        category_id: number;
        category_name: string;
        merchant_pattern: string;
        precedence:
            | 'proposed_wins'
            | 'existing_wins'
            | 'equal_same_target'
            | 'equal_conflict';
    }[];
    future_behavior: {
        wins_over: number;
        ties: number;
        loses_to: number;
    };
    blocked: boolean;
};

export type LearnedRuleHistoricalApplicationPreview = {
    id: number;
    rule_id: number;
    rule_revision: number;
    transaction_count: number;
    items: {
        transaction_id: number;
        merchant_description: string;
        expected_revision: number;
        previous_category_name: string | null;
    }[];
};

export type LearnedRuleBulkAction = {
    id: number;
    rule_id: number;
    rule_revision: number;
    status: 'previewed' | 'applied' | 'undone';
    transaction_count: number;
    restored_count: number;
    skipped_count: number;
};
