import { Form, Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronUp, FileText, Save, Tag } from 'lucide-react';
import { useState } from 'react';
import { update as updateClassification } from '@/actions/App/Http/Controllers/BreakdownTransactionClassificationController';
import { LocalTimestamp } from '@/components/date-time';
import InputError from '@/components/input-error';
import { TransactionEditor } from '@/components/transaction-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    incomeSourceLabel,
    movementDescription,
    movementSupportsCategory,
    transferPurposeLabel,
} from '@/lib/money-movement';
import { CategorySplit } from './category-split';
import {
    CategoryClassificationSelect,
    IncomeSourceClassificationSelect,
    pickerCategoryOptions,
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
            <p className="rounded-lg border bg-muted/30 p-3 type-body text-muted-foreground">
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
                            <p className="type-meta">
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
    const [editing, setEditing] = useState(false);

    if (editing) {
        return (
            <TransactionEditor
                key={transaction.id}
                transaction={transaction}
                currency={transaction.currency}
                today={transaction.occurred_on}
                categoryOptions={pickerCategoryOptions(categoryOptions)}
                onCancel={() => setEditing(false)}
                onSaved={() => setEditing(false)}
            />
        );
    }

    return (
        <div className="grid gap-5 border-t bg-muted/20 p-4 md:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h4 className="type-section-title">
                            Transaction details
                        </h4>
                        <Badge variant="outline">Confirmed</Badge>
                        {transaction.split !== null && (
                            <Badge variant="secondary">Category split</Badge>
                        )}
                    </div>
                    <p className="type-body text-muted-foreground">
                        Transaction #{transaction.id}. Classification stays
                        editable even after an exact merchant rule applies.
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        Edit Transaction
                    </Button>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={closeHref} preserveScroll preserveState>
                            <ChevronUp /> Close
                        </Link>
                    </Button>
                </div>
            </div>

            <dl className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-lg border bg-background p-3">
                    <dt className="type-meta">Amount</dt>
                    <dd className="font-semibold tabular-nums">
                        {formatMinorUnits(
                            transaction.amount_minor,
                            transaction.currency,
                        )}
                    </dd>
                </div>
                <div className="rounded-lg border bg-background p-3">
                    <dt className="type-meta">Meaning</dt>
                    <dd className="font-medium">
                        {movementDescription({
                            kind: transaction.kind,
                            transferPurpose: transaction.transfer_purpose,
                        })}
                    </dd>
                </div>
                <div className="rounded-lg border bg-background p-3">
                    <dt className="type-meta">Classification</dt>
                    <dd className="font-medium">
                        {movementSummary(transaction)}
                    </dd>
                </div>
            </dl>

            <section className="grid gap-2">
                <h4 className="flex items-center gap-2 type-section-title">
                    <Tag className="size-4" /> Inline classification
                </h4>
                <InlineClassification
                    transaction={transaction}
                    categoryOptions={categoryOptions}
                    incomeSourceOptions={orderedIncomeSources}
                />
            </section>

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
                <dl className="grid gap-3 border-t p-4 type-body sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">Confirmed</dt>
                        <dd className="font-medium">
                            <LocalTimestamp value={transaction.confirmed_at} />
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
