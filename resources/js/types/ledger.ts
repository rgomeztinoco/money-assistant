export type Currency = 'USD' | 'PEN';
export type TransactionKind = 'purchase' | 'refund';
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

export type LedgerCategory = {
    id: number;
    name: string;
    provenance: 'owner' | 'linked_refund' | 'learned_rule' | 'ai';
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

export type DuplicateTransaction = RelatedTransaction & {
    revision: number;
    original_purchase_id: number | null;
    has_linked_refunds: boolean;
    has_receipt_breakdown: boolean;
    protects_resolved_duplicate: boolean;
    source_reference_count: number;
    source_reference_fingerprint: string;
};

export type DuplicateRelationship = {
    id: number;
    revision: number;
    status: 'suspected' | 'resolved';
    resolved_at: string | null;
    survivor_transaction_id: number | null;
    voided_transaction_id: number | null;
    resolution_idempotency_key: string;
    reopen_idempotency_key: string;
    other_transaction: RelatedTransaction;
    first_transaction: DuplicateTransaction;
    second_transaction: DuplicateTransaction;
};

export type SelectedTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    confirmed_at: string;
    revision: number;
    voided_at: string | null;
    category: LedgerCategory | null;
    review: {
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
    corrections: Array<{
        id: number;
        field: ReviewableFieldName;
        field_label: string;
        previous_value: string;
        corrected_value: string;
        transaction_revision: number;
        created_at: string | null;
    }>;
    state_changes: Array<{
        id: number;
        operation: 'void' | 'restore';
        result_revision: number;
        result_voided_at: string | null;
        created_at: string | null;
    }>;
    source_reference_count: number;
    source_references: Array<{
        id: number;
        processing_outcome: string;
        created_at: string | null;
    }>;
    duplicate_relationships: DuplicateRelationship[];
    state_change_idempotency_key: string;
};

export type LedgerFilters = {
    search: string;
    date_from: string | null;
    date_to: string | null;
    currency: Currency | 'all';
    category_state: 'all' | 'categorized' | 'uncategorized';
    review_state: 'all' | 'outstanding' | 'clear';
    refund_relationship: 'all' | 'linked' | 'unlinked' | 'not_applicable';
    void_state: 'all' | 'active' | 'voided';
    duplicate_status: 'all' | 'suspected' | 'resolved' | 'none';
};
