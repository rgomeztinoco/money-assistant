export type Currency = 'USD' | 'PEN';
export type TransactionKind = 'spending' | 'refund' | 'income' | 'transfer';
export type TransactionDirection = 'debit' | 'credit';
export type IncomeSource =
    'salary' | 'independent_work' | 'investments' | 'other';
export type TransferPurpose = 'savings' | 'card_payment' | 'internal';

export type MoneyMovementDetails =
    | {
          kind: 'spending' | 'refund';
          direction: TransactionDirection;
          income_source: null;
          transfer_purpose: null;
      }
    | {
          kind: 'income';
          direction: TransactionDirection;
          income_source: IncomeSource;
          transfer_purpose: null;
      }
    | {
          kind: 'transfer';
          direction: TransactionDirection;
          income_source: null;
          transfer_purpose: TransferPurpose;
      };
export type ReviewableFieldName =
    | 'occurred_on'
    | 'amount_minor'
    | 'currency'
    | 'kind'
    | 'merchant_description';

export type ReviewField = {
    name: ReviewableFieldName;
    label: string;
    value: string;
};

export type CategoryAssignmentProvenance = {
    source: 'owner' | 'merchant_rule';
    owner: {
        id: number;
        name: string;
    } | null;
    merchant_rule: {
        id: number;
    } | null;
};

export type LedgerCategory = {
    id: number;
    name: string;
    provenance: CategoryAssignmentProvenance;
};

export type RelatedTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    category_name: string | null;
};

export type ReceiptLineItem = {
    id: string;
    description: string;
    quantity: string | null;
    unit_price_minor: string | null;
    line_total_minor: string;
    category: {
        id: number;
        name: string;
    } | null;
};

export type ReceiptBreakdown = {
    id: number;
    total_minor: string;
    line_items: ReceiptLineItem[];
};

type SelectedTransactionBase = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    merchant_description: string;
    payment_instrument_label: string | null;
    payment_instrument_last_four: string | null;
    confirmed_at: string;
    voided_at: string | null;
    category: LedgerCategory | null;
    review: {
        category: boolean;
        fields: ReviewField[];
        refund_relationship_reasons: Array<{
            name:
                | 'cumulative_refunds_exceed_purchase'
                | 'receipt_breakdown_allocation_requires_review';
            label: string;
        }>;
    };
    original_purchase: RelatedTransaction | null;
    linked_refunds: RelatedTransaction[];
    source_reference_count: number;
    source_references: Array<{
        id: number;
        processing_outcome: string;
        created_at: string | null;
    }>;
    receipt_breakdown: ReceiptBreakdown | null;
    purchase_options: Array<{
        id: number;
        occurred_on: string;
        merchant_description: string;
        currency: Currency;
    }>;
};

export type SelectedTransaction = SelectedTransactionBase &
    MoneyMovementDetails;

type LedgerTransactionBase = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    merchant_description: string;
    original_purchase: {
        id: number;
        merchant_description: string;
    } | null;
    category: LedgerCategory | null;
    review_state: 'outstanding' | 'clear';
    review_field_count: number;
    refund_relationship_review_count: number;
    voided_at: string | null;
};

export type LedgerTransaction = LedgerTransactionBase & MoneyMovementDetails;

export type LedgerFilters = {
    search: string;
    date_from: string | null;
    date_to: string | null;
    currency: Currency | 'all';
    kind: TransactionKind | 'all';
    category_id: number | null;
    category_state: 'all' | 'categorized' | 'uncategorized';
    review_state: 'all' | 'outstanding' | 'clear';
    refund_relationship: 'all' | 'linked' | 'unlinked' | 'not_applicable';
    void_state: 'all' | 'active' | 'voided';
};
