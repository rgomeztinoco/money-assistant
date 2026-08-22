import type { StatementClassification } from '@/types';

export const statementMovementClassificationOptions: Array<{
    value: StatementClassification;
    label: string;
    contributesToSpending: boolean;
}> = [
    {
        value: 'needs_classification',
        label: 'Needs classification',
        contributesToSpending: false,
    },
    { value: 'purchase', label: 'Purchase', contributesToSpending: true },
    { value: 'refund', label: 'Refund', contributesToSpending: true },
    { value: 'fee', label: 'Bank fee', contributesToSpending: true },
    { value: 'tax', label: 'Tax', contributesToSpending: true },
    { value: 'income', label: 'Income', contributesToSpending: false },
    {
        value: 'transfer',
        label: 'Transfer or payment',
        contributesToSpending: false,
    },
    {
        value: 'card_payment',
        label: 'Card payment',
        contributesToSpending: false,
    },
    { value: 'savings', label: 'Savings', contributesToSpending: false },
    {
        value: 'not_a_movement',
        label: 'Not a movement',
        contributesToSpending: false,
    },
];

const statementMovementClassifications = new Map(
    statementMovementClassificationOptions.map((classification) => [
        classification.value,
        classification,
    ]),
);

export function statementMovementClassificationLabel(
    classification: StatementClassification,
): string {
    if (classification === 'already_recorded') {
        return 'Already recorded';
    }

    return (
        statementMovementClassifications.get(classification)?.label ??
        classification
    );
}

export function statementMovementContributesToSpending(
    classification: StatementClassification,
): boolean {
    return (
        statementMovementClassifications.get(classification)
            ?.contributesToSpending ?? false
    );
}
