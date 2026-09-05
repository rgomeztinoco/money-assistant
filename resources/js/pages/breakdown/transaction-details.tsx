import { Form, Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronUp, CircleDollarSign, FileText, Save, Tag } from 'lucide-react';
import { useState } from 'react';
import { update as updateClassification } from '@/actions/App/Http/Controllers/BreakdownTransactionClassificationController';
import { update as updateTransaction } from '@/actions/App/Http/Controllers/TransactionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
    incomeSourceLabel,
    incomeSourceOptions,
    movementDescription,
    movementDirectionOptions,
    movementKindFromValue,
    movementKindOptions,
    movementSupportsCategory,
    transferPurposeLabel,
    transferPurposeOptions,
} from '@/lib/money-movement';
import type { MovementDirection, TransactionKind } from '@/types';
import { CategorySplit } from './category-split';
import {
    CategoryClassificationSelect,
    IncomeSourceClassificationSelect,
} from './classification-select';
import type { BreakdownProps, BreakdownTransaction } from './types';

function InlineClassification({
    transaction,
    categoryOptions,
    incomeSourceOptions: orderedIncomeSources,
}: {
    transaction: BreakdownTransaction;
    categoryOptions: BreakdownProps['category_options'];
    incomeSourceOptions: BreakdownProps['income_source_options'];
}) {
    if (transaction.kind === 'transfer') {
        return (
            <p className="rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground">
                Transfers use a Transfer Purpose instead of a Category.
            </p>
        );
    }

    if (transaction.kind === 'income') {
        return (
            <Form
                {...updateClassification.form(transaction.id)}
                options={{ preserveScroll: true, preserveState: true }}
                className="grid gap-3 rounded-lg border bg-background p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-income-source`}
                            >
                                Income Source
                            </Label>
                            <IncomeSourceClassificationSelect
                                id={`transaction-${transaction.id}-income-source`}
                                name="income_source"
                                value={transaction.income_source}
                                incomeSourceOptions={orderedIncomeSources}
                            />
                            <InputError message={errors.income_source} />
                        </div>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <Save />}
                            Save
                        </Button>
                    </>
                )}
            </Form>
        );
    }

    const otherHistoricalMatches = Math.max(
        0,
        transaction.merchant_match_count - 1,
    );

    return (
        <Form
            {...updateClassification.form(transaction.id)}
            options={{ preserveScroll: true, preserveState: true }}
            className="grid gap-3 rounded-lg border bg-background p-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-category`}
                            >
                                Category
                            </Label>
                            <CategoryClassificationSelect
                                id={`transaction-${transaction.id}-category`}
                                name="category_id"
                                value={
                                    transaction.category?.id.toString() ?? ''
                                }
                                categoryOptions={categoryOptions}
                            />
                            <InputError message={errors.category_id} />
                        </div>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <Save />}
                            Save
                        </Button>
                    </div>

                    <div className="flex items-start gap-3 rounded-md bg-muted/50 p-3">
                        <input
                            id={`transaction-${transaction.id}-matching`}
                            name="apply_to_matching"
                            type="checkbox"
                            value="1"
                            className="mt-0.5 size-4 rounded border-input"
                        />
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`transaction-${transaction.id}-matching`}
                            >
                                Confirm exact merchant match
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Update {otherHistoricalMatches}{' '}
                                {otherHistoricalMatches === 1
                                    ? 'other matching historical Transaction'
                                    : 'other matching historical Transactions'}{' '}
                                and classify future exact matches. You can still
                                edit one-off exceptions afterward.
                            </p>
                            <InputError
                                message={
                                    errors.apply_to_matching ??
                                    errors.classification
                                }
                            />
                        </div>
                    </div>
                </>
            )}
        </Form>
    );
}

function TransactionEditForm({
    transaction,
}: {
    transaction: BreakdownTransaction;
}) {
    const [kind, setKind] = useState<TransactionKind>(transaction.kind);
    const [direction, setDirection] = useState<MovementDirection>(
        transaction.direction,
    );

    function changeDirection(value: string): void {
        if (value === 'debit' || value === 'credit') {
            setDirection(value);
        }
    }

    return (
        <Form
            {...updateTransaction.form(transaction.id)}
            options={{ preserveScroll: true, preserveState: true }}
            className="grid gap-4 border-t p-4"
        >
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="original_spending_id"
                        value={transaction.original_spending_id ?? ''}
                    />
                    <input
                        type="hidden"
                        name="category_id"
                        value={transaction.category?.id ?? ''}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`edit-${transaction.id}-occurred-on`}
                            >
                                Occurrence date
                            </Label>
                            <Input
                                id={`edit-${transaction.id}-occurred-on`}
                                name="occurred_on"
                                type="date"
                                defaultValue={transaction.occurred_on}
                            />
                            <InputError message={errors.occurred_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit-${transaction.id}-amount`}>
                                Amount
                            </Label>
                            <Input
                                id={`edit-${transaction.id}-amount`}
                                name="amount"
                                inputMode="decimal"
                                defaultValue={minorUnitsToCurrencyUnits(
                                    transaction.amount_minor,
                                )}
                            />
                            <InputError
                                message={errors.amount ?? errors.amount_minor}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit-${transaction.id}-currency`}>
                                Currency
                            </Label>
                            <NativeSelect
                                id={`edit-${transaction.id}-currency`}
                                name="currency"
                                defaultValue={transaction.currency}
                                options={[
                                    { value: 'PEN', label: 'PEN' },
                                    { value: 'USD', label: 'USD' },
                                ]}
                            />
                            <InputError message={errors.currency} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit-${transaction.id}-kind`}>
                                Transaction Kind
                            </Label>
                            <NativeSelect
                                id={`edit-${transaction.id}-kind`}
                                name="kind"
                                value={kind}
                                onChange={(event) =>
                                    setKind(
                                        movementKindFromValue(
                                            event.target.value,
                                        ),
                                    )
                                }
                                options={movementKindOptions}
                            />
                            <InputError message={errors.kind} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor={`edit-${transaction.id}-direction`}>
                                Movement Direction
                            </Label>
                            <NativeSelect
                                id={`edit-${transaction.id}-direction`}
                                name="direction"
                                value={direction}
                                onChange={(event) =>
                                    changeDirection(event.target.value)
                                }
                                options={movementDirectionOptions}
                            />
                            <InputError message={errors.direction} />
                        </div>
                    </div>

                    {kind === 'income' && (
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`edit-${transaction.id}-income-source`}
                            >
                                Income Source
                            </Label>
                            <NativeSelect
                                id={`edit-${transaction.id}-income-source`}
                                name="income_source"
                                defaultValue={
                                    transaction.kind === 'income'
                                        ? transaction.income_source
                                        : 'other'
                                }
                                options={incomeSourceOptions}
                            />
                            <InputError message={errors.income_source} />
                        </div>
                    )}

                    {kind === 'transfer' && (
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`edit-${transaction.id}-transfer-purpose`}
                            >
                                Transfer Purpose
                            </Label>
                            <NativeSelect
                                id={`edit-${transaction.id}-transfer-purpose`}
                                name="transfer_purpose"
                                defaultValue={
                                    transaction.kind === 'transfer'
                                        ? transaction.transfer_purpose
                                        : 'internal'
                                }
                                options={transferPurposeOptions}
                            />
                            <InputError message={errors.transfer_purpose} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor={`edit-${transaction.id}-description`}>
                            Merchant or description
                        </Label>
                        <Input
                            id={`edit-${transaction.id}-description`}
                            name="description"
                            defaultValue={transaction.description}
                            maxLength={255}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`edit-${transaction.id}-instrument`}
                            >
                                Account or card
                            </Label>
                            <Input
                                id={`edit-${transaction.id}-instrument`}
                                name="instrument_label"
                                defaultValue={
                                    transaction.instrument_label ?? ''
                                }
                                maxLength={100}
                            />
                            <InputError message={errors.instrument_label} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`edit-${transaction.id}-last-four`}>
                                Last four digits
                            </Label>
                            <Input
                                id={`edit-${transaction.id}-last-four`}
                                name="instrument_last_four"
                                defaultValue={
                                    transaction.instrument_last_four ?? ''
                                }
                                inputMode="numeric"
                                pattern="[0-9]{4}"
                                maxLength={4}
                            />
                            <InputError message={errors.instrument_last_four} />
                        </div>
                    </div>

                    {movementSupportsCategory(kind) &&
                        transaction.split !== null && (
                            <div className="flex items-start gap-3 rounded-lg border p-3">
                                <input
                                    id={`edit-${transaction.id}-remove-split`}
                                    name="remove_receipt_breakdown"
                                    type="checkbox"
                                    value="1"
                                    className="mt-0.5 size-4 rounded border-input"
                                />
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`edit-${transaction.id}-remove-split`}
                                    >
                                        Remove Category split if the amount no
                                        longer reconciles
                                    </Label>
                                    <InputError
                                        message={
                                            errors.remove_receipt_breakdown
                                        }
                                    />
                                </div>
                            </div>
                        )}

                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Save Transaction
                    </Button>
                </>
            )}
        </Form>
    );
}

function movementSummary(transaction: BreakdownTransaction): string {
    if (transaction.kind === 'income') {
        return incomeSourceLabel(transaction.income_source);
    }

    if (transaction.kind === 'transfer') {
        return transferPurposeLabel(transaction.transfer_purpose);
    }

    return transaction.category?.name ?? 'Uncategorized';
}

export function TransactionDetails({
    transaction,
    categoryOptions,
    incomeSourceOptions: orderedIncomeSources,
    closeHref,
}: {
    transaction: BreakdownTransaction;
    categoryOptions: BreakdownProps['category_options'];
    incomeSourceOptions: BreakdownProps['income_source_options'];
    closeHref: NonNullable<InertiaLinkProps['href']>;
}) {
    return (
        <div className="grid gap-5 border-t bg-muted/20 p-4 md:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h4 className="font-semibold">Transaction details</h4>
                        <Badge variant="outline">Confirmed</Badge>
                        {transaction.split !== null && (
                            <Badge variant="secondary">Category split</Badge>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Transaction #{transaction.id}. Classification stays
                        editable even after an exact merchant rule applies.
                    </p>
                </div>
                <Button asChild variant="ghost" size="sm">
                    <Link href={closeHref} preserveScroll>
                        <ChevronUp /> Close
                    </Link>
                </Button>
            </div>

            <dl className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-lg border bg-background p-3">
                    <dt className="text-xs text-muted-foreground">Amount</dt>
                    <dd className="font-semibold tabular-nums">
                        {formatMinorUnits(
                            transaction.amount_minor,
                            transaction.currency,
                        )}
                    </dd>
                </div>
                <div className="rounded-lg border bg-background p-3">
                    <dt className="text-xs text-muted-foreground">Meaning</dt>
                    <dd className="font-medium">
                        {movementDescription({
                            kind: transaction.kind,
                            transferPurpose: transaction.transfer_purpose,
                        })}
                    </dd>
                </div>
                <div className="rounded-lg border bg-background p-3">
                    <dt className="text-xs text-muted-foreground">
                        Classification
                    </dt>
                    <dd className="font-medium">
                        {movementSummary(transaction)}
                    </dd>
                </div>
            </dl>

            <section className="grid gap-2">
                <h4 className="flex items-center gap-2 text-sm font-semibold">
                    <Tag className="size-4" /> Inline classification
                </h4>
                <InlineClassification
                    transaction={transaction}
                    categoryOptions={categoryOptions}
                    incomeSourceOptions={orderedIncomeSources}
                />
            </section>

            <details className="rounded-lg border bg-background">
                <summary className="flex cursor-pointer items-center gap-2 px-4 py-3 font-medium">
                    <CircleDollarSign className="size-4" /> Edit Transaction
                </summary>
                <TransactionEditForm
                    key={`${transaction.id}-${transaction.kind}-${transaction.amount_minor}`}
                    transaction={transaction}
                />
            </details>

            {movementSupportsCategory(transaction.kind) && (
                <CategorySplit
                    key={`${transaction.id}-${transaction.split?.map((row) => row.id).join('-') ?? 'none'}`}
                    transaction={transaction}
                    categoryOptions={categoryOptions}
                />
            )}

            <details className="rounded-lg border bg-background">
                <summary className="flex cursor-pointer items-center gap-2 px-4 py-3 font-medium">
                    <FileText className="size-4" /> Optional source details
                </summary>
                <dl className="grid gap-3 border-t p-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">Confirmed</dt>
                        <dd className="font-medium">
                            {transaction.confirmed_at.slice(0, 10)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            Movement Direction
                        </dt>
                        <dd className="font-medium">
                            {transaction.direction === 'debit'
                                ? 'Money out'
                                : 'Money in'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            Account or card
                        </dt>
                        <dd className="font-medium">
                            {transaction.instrument_label ?? 'Not recorded'}
                            {transaction.instrument_last_four
                                ? ` · ${transaction.instrument_last_four}`
                                : ''}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Source</dt>
                        <dd className="font-medium">
                            {transaction.statement_import_id === null
                                ? 'Manual or Spending Notification'
                                : `Statement Import #${transaction.statement_import_id}`}
                        </dd>
                    </div>
                </dl>
            </details>
        </div>
    );
}
