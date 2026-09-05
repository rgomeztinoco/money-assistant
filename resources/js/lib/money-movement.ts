import type {
    IncomeSource,
    MovementDirection,
    TransactionKind,
    TransferPurpose,
} from '@/types';

export const movementKindOptions: ReadonlyArray<{
    value: TransactionKind;
    label: string;
}> = [
    { value: 'spending', label: 'Spending' },
    { value: 'refund', label: 'Refund or reimbursement' },
    { value: 'income', label: 'Income' },
    { value: 'transfer', label: 'Transfer' },
];

export const movementDirectionOptions: ReadonlyArray<{
    value: MovementDirection;
    label: string;
}> = [
    { value: 'debit', label: 'Money out' },
    { value: 'credit', label: 'Money in' },
];

export const incomeSourceOptions: ReadonlyArray<{
    value: IncomeSource;
    label: string;
}> = [
    { value: 'salary', label: 'Salary' },
    { value: 'independent_work', label: 'Independent work' },
    { value: 'investments', label: 'Investments' },
    { value: 'other', label: 'Other income' },
];

export const transferPurposeOptions: ReadonlyArray<{
    value: TransferPurpose;
    label: string;
}> = [
    { value: 'savings', label: 'Moved to savings' },
    { value: 'card_payment', label: 'Card payment' },
    { value: 'internal', label: 'Other transfer' },
];

export function movementKindFromValue(value: string): TransactionKind {
    switch (value) {
        case 'spending':
        case 'refund':
        case 'income':
        case 'transfer':
            return value;
        default:
            return 'spending';
    }
}

export function movementKindLabel(kind: TransactionKind): string {
    return (
        movementKindOptions.find((option) => option.value === kind)?.label ??
        kind
    );
}

export function incomeSourceLabel(source: IncomeSource): string {
    return (
        incomeSourceOptions.find((option) => option.value === source)?.label ??
        source
    );
}

export function transferPurposeLabel(purpose: TransferPurpose): string {
    return (
        transferPurposeOptions.find((option) => option.value === purpose)
            ?.label ?? purpose
    );
}

export function movementSupportsCategory(kind: TransactionKind): boolean {
    return kind === 'spending' || kind === 'refund';
}

export function movementDescription({
    kind,
    transferPurpose,
}: {
    kind: TransactionKind;
    transferPurpose: TransferPurpose | null;
}): string {
    if (kind !== 'transfer') {
        return movementKindLabel(kind);
    }

    const purpose = transferPurposeOptions.find(
        (option) => option.value === transferPurpose,
    );

    return `Transfer · ${purpose?.label ?? 'Other transfer'}`;
}
