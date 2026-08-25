import type { Currency } from './ledger';
import type { TransactionKind, TransferPurpose } from './ledger';

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
    | 'savings'
    | 'already_recorded'
    | 'not_a_movement';

export type StatementMatchCandidate = {
    id: number;
    occurred_on: string;
    description: string;
    instrument_label: string | null;
    instrument_last_four: string | null;
    kind: TransactionKind;
    transfer_purpose: TransferPurpose | null;
    compatible_classifications: StatementClassification[];
    date_difference_days: number;
    evidence: Record<string, boolean>;
};

export type StatementMovementMatch =
    | {
          status: 'new';
          transaction_id: null;
          candidates: StatementMatchCandidate[];
          evidence: Record<string, boolean | number | string | null>;
      }
    | {
          status: 'matched';
          transaction_id: number;
          candidates: StatementMatchCandidate[];
          evidence: Record<string, boolean | number | string | null>;
      }
    | {
          status: 'ambiguous';
          transaction_id: null;
          candidates: StatementMatchCandidate[];
          evidence: Record<string, boolean | number | string | null>;
      };

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
    match: StatementMovementMatch;
};

type StatementConfirmationMovementDetails = Pick<
    StatementPreviewMovement,
    | 'source_row_id'
    | 'occurred_on'
    | 'description'
    | 'amount_minor'
    | 'currency'
    | 'classification'
>;

export type StatementConfirmationMovement =
    StatementConfirmationMovementDetails &
        (
            | { resolution: 'link'; transaction_id: number }
            | {
                  resolution: 'create' | 'exclude' | 'needs_resolution';
                  transaction_id: null;
              }
        );

export type StatementImportConfirmation = {
    file_hash: string;
    instrument_label: string;
    instrument_last_four: string | null;
    movements: StatementConfirmationMovement[];
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
    confirmation: StatementImportConfirmation;
};
