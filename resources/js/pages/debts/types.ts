import type { Currency } from '@/types';

export type Debt = {
    id: number;
    name: string;
    counterparty: string;
    direction: 'owed' | 'receivable';
    currency: Currency;
    opening_balance_minor: string;
    opened_on: string;
    balance_minor: string;
    status: 'active' | 'settled';
};
export type DebtEntry = {
    id: number;
    kind: 'funding' | 'repayment' | 'adjustment';
    amount_minor: string;
    occurred_on: string;
    reason: string | null;
    transaction_id: number | null;
    voided: boolean;
};
export type DebtTransactionOption = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    direction: 'debit' | 'credit';
    description: string;
};
