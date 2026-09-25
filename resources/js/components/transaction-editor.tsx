import type { FormComponentRef, FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/TransactionController';
import { CategoryPicker } from '@/components/category-picker';
import type { CategoryPickerOption } from '@/components/category-picker';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    formatMinorUnits,
    minorUnitsToCurrencyUnits,
} from '@/lib/format-minor-units';
import {
    incomeSourceOptions,
    movementDirectionOptions,
    movementKindFromValue,
    movementKindOptions,
    movementSupportsCategory,
    transferPurposeOptions,
} from '@/lib/money-movement';
import type {
    Currency,
    IncomeSource,
    MovementDirection,
    TransactionKind,
    TransferPurpose,
} from '@/types';

export type EditorTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    direction: MovementDirection;
    description: string;
    income_source: IncomeSource | null;
    transfer_purpose: TransferPurpose | null;
    category: { id: number; name: string } | null;
    original_spending_id: number | null;
    instrument_label: string | null;
    instrument_last_four: string | null;
    split: Array<{
        id: string;
        amount_minor: string;
        category: { id: number; name: string } | null;
    }> | null;
};

function normalizedAmount(value: string): string | null {
    const parts = /^0*(\d+)(?:\.(\d{1,2}))?$/.exec(value);

    return parts === null
        ? null
        : `${parts[1]}.${(parts[2] ?? '').padEnd(2, '0')}`;
}

export function TransactionEditor({
    transaction,
    currency,
    today,
    categoryOptions,
    onCancel,
    onSaved,
}: {
    transaction?: EditorTransaction;
    currency: Currency;
    today: string;
    categoryOptions: CategoryPickerOption[];
    onCancel: () => void;
    onSaved: () => void;
}) {
    const form = useRef<FormComponentRef>(null);
    const splitRemovalConfirmed = useRef(false);
    const [kind, setKind] = useState<TransactionKind>(
        transaction?.kind ?? 'spending',
    );
    const [direction, setDirection] = useState<MovementDirection>(
        transaction?.direction ?? 'debit',
    );
    const [amount, setAmount] = useState(
        transaction ? minorUnitsToCurrencyUnits(transaction.amount_minor) : '',
    );
    const [categoryId, setCategoryId] = useState(
        transaction?.category?.id.toString() ?? '',
    );
    const [categoryTouched, setCategoryTouched] = useState(false);
    const [optionalOpen, setOptionalOpen] = useState(false);
    const [confirmationOpen, setConfirmationOpen] = useState(false);

    const removesSplit =
        transaction?.split !== null &&
        transaction?.split !== undefined &&
        (!movementSupportsCategory(kind) ||
            (normalizedAmount(amount) !== null &&
                normalizedAmount(amount) !==
                    normalizedAmount(
                        minorUnitsToCurrencyUnits(transaction.amount_minor),
                    )));
    const removesCategory =
        transaction?.category !== null &&
        transaction?.category !== undefined &&
        (!movementSupportsCategory(kind) || categoryId === '');
    const removesOriginal =
        transaction?.original_spending_id !== null &&
        transaction?.original_spending_id !== undefined &&
        kind !== 'refund';
    const consequences = [
        removesCategory ? `Category: ${transaction.category?.name}` : null,
        removesSplit ? 'Category split and its allocations' : null,
        removesOriginal
            ? `Original Spending link: Transaction #${transaction.original_spending_id}`
            : null,
    ].filter((consequence): consequence is string => consequence !== null);

    function changeKind(value: string): void {
        splitRemovalConfirmed.current = false;
        const nextKind = movementKindFromValue(value);
        setKind(nextKind);

        if (!transaction) {
            setDirection(
                nextKind === 'refund' || nextKind === 'income'
                    ? 'credit'
                    : 'debit',
            );
        }
    }

    function confirmSave(): void {
        splitRemovalConfirmed.current = Boolean(
            removesSplit && movementSupportsCategory(kind),
        );
        setConfirmationOpen(false);
        window.setTimeout(() => form.current?.submit(), 0);
    }

    return (
        <>
            <Form
                ref={form}
                {...(transaction ? update.form(transaction.id) : store.form())}
                options={{ preserveScroll: true, preserveState: true }}
                transform={(data) => {
                    const payload: Record<string, FormDataConvertible> = {
                        ...data,
                        ...(splitRemovalConfirmed.current && removesSplit
                            ? { remove_receipt_breakdown: '1' }
                            : {}),
                    };

                    if (!transaction && !categoryTouched) {
                        delete payload.category_id;
                    }

                    return payload;
                }}
                onSuccess={onSaved}
                onError={(errors) => {
                    splitRemovalConfirmed.current = false;

                    if (
                        errors.instrument_label ||
                        errors.instrument_last_four
                    ) {
                        setOptionalOpen(true);
                    }
                }}
                onSubmitCapture={(event) => {
                    if (consequences.length > 0 && !confirmationOpen) {
                        event.preventDefault();
                        event.stopPropagation();
                        setConfirmationOpen(true);
                    }
                }}
                className="grid gap-5"
            >
                {({ errors, processing }) => (
                    <>
                        {transaction?.original_spending_id &&
                            kind === 'refund' && (
                                <input
                                    type="hidden"
                                    name="original_spending_id"
                                    value={transaction.original_spending_id}
                                />
                            )}
                        {transaction?.split &&
                            movementSupportsCategory(kind) && (
                                <input
                                    type="hidden"
                                    name="category_id"
                                    value={transaction.category?.id ?? ''}
                                />
                            )}

                        <div className="grid gap-3 rounded-xl border bg-muted/30 p-4 sm:grid-cols-[minmax(0,1fr)_7rem_9rem] sm:items-end">
                            <div className="grid gap-2">
                                <Label htmlFor="transaction-amount">
                                    Amount
                                </Label>
                                <Input
                                    id="transaction-amount"
                                    name="amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={amount}
                                    onChange={(event) => {
                                        splitRemovalConfirmed.current = false;
                                        setAmount(event.currentTarget.value);
                                    }}
                                    placeholder="12.50"
                                    className="h-12 text-2xl font-semibold tabular-nums"
                                />
                                <InputError
                                    message={
                                        errors.amount ?? errors.amount_minor
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="transaction-currency">
                                    Currency
                                </Label>
                                <NativeSelect
                                    id="transaction-currency"
                                    name="currency"
                                    defaultValue={
                                        transaction?.currency ?? currency
                                    }
                                    options={[
                                        { value: 'PEN', label: 'PEN' },
                                        { value: 'USD', label: 'USD' },
                                    ]}
                                    className="h-12"
                                />
                                <InputError message={errors.currency} />
                                <InputError
                                    message={errors.original_spending_id}
                                />
                                {errors.original_spending_id &&
                                    transaction?.original_spending_id && (
                                        <p className="text-xs text-muted-foreground">
                                            This Refund links to original
                                            Spending #
                                            {transaction.original_spending_id}.
                                            Keep their currencies the same to
                                            save.
                                        </p>
                                    )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="transaction-direction">
                                    Movement Direction
                                </Label>
                                <NativeSelect
                                    id="transaction-direction"
                                    name="direction"
                                    value={direction}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;

                                        if (
                                            value === 'debit' ||
                                            value === 'credit'
                                        ) {
                                            setDirection(value);
                                        }
                                    }}
                                    options={movementDirectionOptions}
                                    className="h-12"
                                />
                                <InputError message={errors.direction} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="transaction-description">
                                    Merchant or description
                                </Label>
                                <Input
                                    id="transaction-description"
                                    name="description"
                                    defaultValue={
                                        transaction?.description ?? ''
                                    }
                                    maxLength={255}
                                    autoComplete="off"
                                />
                                <InputError message={errors.description} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="transaction-kind">
                                    Transaction Kind
                                </Label>
                                <NativeSelect
                                    id="transaction-kind"
                                    name="kind"
                                    value={kind}
                                    onChange={(event) =>
                                        changeKind(event.currentTarget.value)
                                    }
                                    options={movementKindOptions}
                                />
                                <InputError message={errors.kind} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="transaction-date">
                                    Occurrence date
                                </Label>
                                <Input
                                    id="transaction-date"
                                    name="occurred_on"
                                    type="date"
                                    defaultValue={
                                        transaction?.occurred_on ?? today
                                    }
                                />
                                <InputError message={errors.occurred_on} />
                            </div>

                            {kind === 'income' && (
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="transaction-income-source">
                                        Income Source
                                    </Label>
                                    <NativeSelect
                                        id="transaction-income-source"
                                        name="income_source"
                                        defaultValue={
                                            transaction?.income_source ??
                                            'salary'
                                        }
                                        options={incomeSourceOptions}
                                    />
                                    <InputError
                                        message={errors.income_source}
                                    />
                                </div>
                            )}
                            {kind === 'transfer' && (
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="transaction-transfer-purpose">
                                        Transfer Purpose
                                    </Label>
                                    <NativeSelect
                                        id="transaction-transfer-purpose"
                                        name="transfer_purpose"
                                        defaultValue={
                                            transaction?.transfer_purpose ??
                                            'internal'
                                        }
                                        options={transferPurposeOptions}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Other transfer includes movements
                                        between your accounts.
                                    </p>
                                    <InputError
                                        message={errors.transfer_purpose}
                                    />
                                </div>
                            )}
                            {movementSupportsCategory(kind) &&
                                (transaction?.split ? (
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label>Category split</Label>
                                        <div className="rounded-lg border p-3 text-sm">
                                            {transaction.split.map((row) => (
                                                <div
                                                    key={row.id}
                                                    className="flex justify-between gap-4"
                                                >
                                                    <span>
                                                        {row.category?.name ??
                                                            'Uncategorized'}
                                                    </span>
                                                    <span className="tabular-nums">
                                                        {formatMinorUnits(
                                                            row.amount_minor,
                                                            transaction.currency,
                                                        )}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            Use Split by Category below to
                                            manage these amounts.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="transaction-category-trigger">
                                            Category
                                        </Label>
                                        <CategoryPicker
                                            id="transaction-category"
                                            name="category_id"
                                            value={categoryId}
                                            onValueChange={(value) => {
                                                setCategoryId(value);
                                                setCategoryTouched(true);
                                            }}
                                            emptyLabel="Uncategorized"
                                            options={
                                                transaction?.category &&
                                                !categoryOptions.some(
                                                    (option) =>
                                                        option.id ===
                                                        transaction.category
                                                            ?.id,
                                                )
                                                    ? [
                                                          ...categoryOptions,
                                                          {
                                                              id: transaction
                                                                  .category.id,
                                                              name: transaction
                                                                  .category
                                                                  .name,
                                                              path: `${transaction.category.name} (current; unavailable for new assignments)`,
                                                              parent_id: null,
                                                              parent_name: null,
                                                          },
                                                      ]
                                                    : categoryOptions
                                            }
                                            allowCreate={false}
                                        />
                                        <InputError
                                            message={errors.category_id}
                                        />
                                    </div>
                                ))}
                        </div>

                        <details
                            open={optionalOpen}
                            onToggle={(event) =>
                                setOptionalOpen(event.currentTarget.open)
                            }
                            className="rounded-lg border"
                        >
                            <summary className="cursor-pointer px-4 py-3 text-sm font-medium">
                                Optional payment source
                                {(errors.instrument_label ||
                                    errors.instrument_last_four) &&
                                    ' · Check these fields'}
                            </summary>
                            <div className="grid gap-4 border-t p-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="transaction-instrument-label">
                                        Account or card
                                    </Label>
                                    <Input
                                        id="transaction-instrument-label"
                                        name="instrument_label"
                                        defaultValue={
                                            transaction?.instrument_label ?? ''
                                        }
                                        maxLength={100}
                                    />
                                    <InputError
                                        message={errors.instrument_label}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="transaction-last-four">
                                        Last four digits
                                    </Label>
                                    <Input
                                        id="transaction-last-four"
                                        name="instrument_last_four"
                                        defaultValue={
                                            transaction?.instrument_last_four ??
                                            ''
                                        }
                                        inputMode="numeric"
                                        maxLength={4}
                                    />
                                    <InputError
                                        message={errors.instrument_last_four}
                                    />
                                </div>
                            </div>
                        </details>

                        <div className="flex justify-end gap-2 border-t pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onCancel}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Save Transaction
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            <AlertDialog
                open={confirmationOpen}
                onOpenChange={setConfirmationOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Review information this edit removes
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Saving this Transaction will remove:
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <ul className="list-disc space-y-1 pl-5 text-sm">
                        {consequences.map((consequence) => (
                            <li key={consequence}>{consequence}</li>
                        ))}
                    </ul>
                    {removesSplit && movementSupportsCategory(kind) && (
                        <p className="text-sm text-muted-foreground">
                            The new amount does not match the Category split.
                            Remove the split and save, or continue editing.
                        </p>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel>Continue editing</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmSave}>
                            Remove and save
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
