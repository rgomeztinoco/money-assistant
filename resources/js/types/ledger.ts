import type { LearnedRuleCandidate } from './learned-rule';

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

export type CategoryAssignmentProvenance = {
    source: 'owner' | 'linked_refund' | 'learned_rule' | 'ai';
    owner: {
        id: number;
        name: string;
    } | null;
    linked_purchase: {
        id: number;
        merchant_description: string;
    } | null;
    learned_rule: {
        id: number;
        revision: number;
    } | null;
    bulk_action: {
        id: number;
    } | null;
    ai: {
        classifier_version: string;
        confidence: number;
        outcome: string;
        explanation: string;
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

export type DuplicateTransaction = RelatedTransaction & {
    revision: number;
    original_purchase_id: number | null;
    has_linked_refunds: boolean;
    receipt_breakdown_statuses: Array<'draft' | 'confirmed' | 'superseded'>;
    protects_resolved_duplicate: boolean;
    source_reference_count: number;
    source_reference_fingerprint: string;
    receipt_breakdown_fingerprint: string;
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

export type ReceiptLineItemRole =
    | 'purchased_item'
    | 'tax'
    | 'discount'
    | 'tip'
    | 'fee'
    | 'rounding'
    | 'other_adjustment'
    | 'unidentified';

export type ReceiptLineItem = {
    id: string;
    description: string;
    role: ReceiptLineItemRole;
    quantity: string | null;
    unit_price_minor: string | null;
    line_total_minor: string;
    category: {
        id: number;
        name: string;
    } | null;
    related_line_item_id: string | null;
    requires_review: boolean;
};

export type ReceiptBreakdown = {
    id: number;
    revision: number;
    status: 'draft' | 'confirmed';
    total_minor: string;
    delta_minor: string;
    confirmed_at: string | null;
    line_items: ReceiptLineItem[];
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
    corrections: Array<{
        id: number;
        field: ReviewableFieldName;
        field_label: string;
        previous_value: string;
        corrected_value: string;
        transaction_revision: number;
        created_at: string | null;
    }>;
    ai_category_proposal: {
        id: number;
        revision: number;
        name: string;
        parent_path: string | null;
        description: string | null;
        examples: string[];
    } | null;
    learned_rule_candidate: LearnedRuleCandidate | null;
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
    receipt_breakdown: {
        draft: ReceiptBreakdown | null;
        confirmed: ReceiptBreakdown | null;
    };
    receipt_proposals: Array<{
        id: string;
        processed_at: string;
        proposed_amount_minor: string;
        proposed_merchant_description: string;
        line_item_count: number;
    }>;
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
