import { Form } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    destroy as removeCategorySplit,
    update as saveCategorySplit,
} from '@/actions/App/Http/Controllers/ReceiptBreakdownController';
import InputError from '@/components/input-error';
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
import type { BreakdownProps, BreakdownTransaction } from './types';

type EditableSplitRow = {
    clientId: string;
    amount: string;
    categoryId: string;
};

export function CategorySplit({
    transaction,
    categoryOptions,
}: {
    transaction: BreakdownTransaction;
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
        <details className="rounded-lg border">
            <summary className="cursor-pointer px-4 py-3 font-medium">
                Split by Category
            </summary>
            <div className="grid gap-4 border-t p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">
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
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            {rows.map((row, index) => (
                                <div
                                    key={row.clientId}
                                    className="grid gap-3 rounded-lg border bg-background p-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.45fr)_auto] sm:items-end"
                                >
                                    <input
                                        type="hidden"
                                        name={`line_items[${index}][description]`}
                                        value={`Category split ${index + 1}`}
                                    />
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`split-${transaction.id}-${row.clientId}-category`}
                                        >
                                            Category
                                        </Label>
                                        <CategoryClassificationSelect
                                            id={`split-${transaction.id}-${row.clientId}-category`}
                                            name={`line_items[${index}][category_id]`}
                                            value={row.categoryId}
                                            categoryOptions={categoryOptions}
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `line_items.${index}.category_id`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`split-${transaction.id}-${row.clientId}-amount`}
                                        >
                                            Amount
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
                                    </div>
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
                                </div>
                            ))}

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
                                    className={`text-sm font-medium ${isReconciled ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'}`}
                                    data-test="split-reconciliation"
                                >
                                    {isReconciled
                                        ? 'Amounts reconcile exactly'
                                        : remaining >= 0n
                                          ? `${formatMinorUnits(remaining.toString(), transaction.currency)} remaining`
                                          : `${formatMinorUnits((-remaining).toString(), transaction.currency)} over`}
                                </p>
                            </div>

                            <Button
                                type="submit"
                                disabled={processing || !isReconciled}
                            >
                                {processing && <Spinner />}
                                {transaction.split === null
                                    ? 'Save Category split'
                                    : 'Replace Category split'}
                            </Button>
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
                                className="w-full"
                            >
                                {processing && <Spinner />}
                                Remove Category split
                            </Button>
                        )}
                    </Form>
                )}
            </div>
        </details>
    );
}
