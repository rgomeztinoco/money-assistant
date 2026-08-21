import type { Currency } from './ledger';

export type FinancialStatementFormat = 'bcp' | 'interbank';
export type StatementDirection = 'debit' | 'credit';
export type StatementClassification =
    | 'needs_classification'
    | 'purchase'
    | 'refund'
    | 'fee'
    | 'tax'
    | 'income'
    | 'transfer'
    | 'card_payment'
    | 'warda'
    | 'already_recorded'
    | 'not_a_movement';

export type StatementPreviewMovement = {
    source_row_id: string;
    position: number;
    occurred_on: string;
    description: string;
    amount_minor: string;
    currency: Currency;
    direction: StatementDirection;
    classification: StatementClassification;
    contributes_to_spending: boolean;
    can_be_excluded: boolean;
    source_metadata: Record<string, unknown>;
};

export type StatementImportPreview = {
    financial_statement_format: FinancialStatementFormat;
    parser_version: string;
    file_hash: string;
    period_start: string;
    period_end: string;
    instrument_label: string;
    instrument_last_four: string | null;
    movements: StatementPreviewMovement[];
    informational_values: Array<{
        label: string;
        value: string;
        currency: Currency;
    }>;
    reconciliation: Record<string, string>;
};
