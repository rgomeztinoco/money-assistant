import { Form } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    destroy as removeCategorySplit,
    update as saveCategorySplit,
} from '@/actions/App/Http/Controllers/ReceiptBreakdownController';
import InputError from '@/components/input-error';
import type { EditorTransaction } from '@/components/transaction-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    currencyUnitsToMinorUnits,
    formatMinorUnits,
    minorUnitsToCurrencyUnits,
} from '@/lib/format-minor-units';
import { CategoryClassificationSelect } from './classification-select';
import type { BreakdownProps } from './types';

type EditableSplitRow = {
    clientId: string;
    amount: string;
    categoryId: string;
};

export function CategorySplit({
    transaction,
    categoryOptions,
}: {
    transaction: EditorTransaction;
    categoryOptions: BreakdownProps['category_options'];
}) {
    const nextRowId = useRef(2);
    const [rows, setRows] = useState<EditableSplitRow[]>(
        () =>
            transaction.split?.map((row, index) => ({
                clientId: `${transaction.id}-${row.id}-${index}`,
                amount: minorUnitsToCurrencyUnits(row.amount_minor),
                categoryId: row.category?.id.toString() ?? '',
            })) ?? [
                {
                    clientId: `${transaction.id}-initial-0`,
                    amount: minorUnitsToCurrencyUnits(transaction.amount_minor),
                    categoryId: transaction.category?.id.toString() ?? '',
                },
                {
                    clientId: `${transaction.id}-initial-1`,
                    amount: '',
                    categoryId: '',
                },
            ],
    );

    function updateRow(
        clientId: string,
        field: 'amount' | 'categoryId',
        value: string,
    ): void {
        setRows((currentRows) =>
            currentRows.map((row) =>
                row.clientId === clientId ? { ...row, [field]: value } : row,
            ),
        );
    }

    function addRow(): void {
        const rowId = nextRowId.current;
        nextRowId.current += 1;
        setRows((currentRows) => [
            ...currentRows,
            {
                clientId: `${transaction.id}-added-${rowId}`,
                amount: '',
                categoryId: '',
            },
        ]);
    }

    const parsedAmounts = rows.map((row) =>
        currencyUnitsToMinorUnits(row.amount),
    );
    const hasValidAmounts = parsedAmounts.every(
        (amount) => amount !== null && amount !== 0n,
    );
    let splitTotal = 0n;

    for (const amount of parsedAmounts) {
        splitTotal += amount ?? 0n;
    }

    const transactionTotal = BigInt(transaction.amount_minor);
    const remaining = transactionTotal - splitTotal;
    const isReconciled = hasValidAmounts && remaining === 0n;

    return (
        <div className="grid gap-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="type-body text-muted-foreground">
                    Category amounts must total{' '}
                    {formatMinorUnits(
                        transaction.amount_minor,
                        transaction.currency,
                    )}
                    .
                </p>
                {transaction.split !== null && (
                    <Badge variant="secondary">Split active</Badge>
                )}
            </div>

            <Form
                {...saveCategorySplit.form(transaction.id)}
                options={{ preserveScroll: true, preserveState: true }}
                className="grid gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[26rem] border-collapse type-body">
                                <thead className="border-b bg-muted/40 text-left type-meta">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="w-3/5 px-3 py-2 font-medium"
                                        >
                                            Category
                                        </th>
                                        <th
                                            scope="col"
                                            className="w-36 px-3 py-2 font-medium"
                                        >
                                            Amount
                                        </th>
                                        <th
                                            scope="col"
                                            className="w-12 px-2 py-2"
                                        >
                                            <span className="sr-only">
                                                Remove
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows.map((row, index) => (
                                        <tr key={row.clientId}>
                                            <td className="px-3 py-2 align-top">
                                                <input
                                                    type="hidden"
                                                    name={`line_items[${index}][description]`}
                                                    value={`Category split ${index + 1}`}
                                                />
                                                <Label
                                                    className="sr-only"
                                                    htmlFor={`split-${transaction.id}-${row.clientId}-category`}
                                                >
                                                    Category for row {index + 1}
                                                </Label>
                                                <CategoryClassificationSelect
                                                    id={`split-${transaction.id}-${row.clientId}-category`}
                                                    name={`line_items[${index}][category_id]`}
                                                    value={row.categoryId}
                                                    categoryOptions={
                                                        categoryOptions
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `line_items.${index}.category_id`
                                                        ]
                                                    }
                                                />
                                            </td>
                                            <td className="px-3 py-2 align-top">
                                                <Label
                                                    className="sr-only"
                                                    htmlFor={`split-${transaction.id}-${row.clientId}-amount`}
                                                >
                                                    Amount for row {index + 1}
                                                </Label>
                                                <Input
                                                    id={`split-${transaction.id}-${row.clientId}-amount`}
                                                    name={`line_items[${index}][line_total]`}
                                                    inputMode="decimal"
                                                    value={row.amount}
                                                    onChange={(event) =>
                                                        updateRow(
                                                            row.clientId,
                                                            'amount',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="0.00"
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `line_items.${index}.line_total`
                                                        ]
                                                    }
                                                />
                                            </td>
                                            <td className="px-2 py-2 align-top">
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="ghost"
                                                    disabled={rows.length <= 1}
                                                    onClick={() =>
                                                        setRows((currentRows) =>
                                                            currentRows.filter(
                                                                (candidate) =>
                                                                    candidate.clientId !==
                                                                    row.clientId,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={`Remove split row ${index + 1}`}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addRow}
                            >
                                <Plus /> Add Category amount
                            </Button>
                            <p
                                className={`type-body font-medium ${isReconciled ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'}`}
                                data-test="split-reconciliation"
                            >
                                {isReconciled
                                    ? 'Amounts reconcile exactly'
                                    : remaining >= 0n
                                      ? `${formatMinorUnits(remaining.toString(), transaction.currency)} remaining`
                                      : `${formatMinorUnits((-remaining).toString(), transaction.currency)} over`}
                            </p>
                        </div>

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={processing || !isReconciled}
                            >
                                {processing && <Spinner />}
                                {transaction.split === null
                                    ? 'Save Category split'
                                    : 'Replace Category split'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            {transaction.split !== null && (
                <Form
                    {...removeCategorySplit.form(transaction.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Remove Category split
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}
