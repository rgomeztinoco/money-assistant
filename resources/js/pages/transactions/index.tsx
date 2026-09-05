import { Deferred, Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CircleOff,
    FileUp,
    ListChecks,
    ReceiptText,
    RotateCcw,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import { store as recordTransaction } from '@/actions/App/Http/Controllers/TransactionController';
import {
    destroy as restoreTransaction,
    store as voidTransaction,
} from '@/actions/App/Http/Controllers/TransactionVoidController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { TransactionInspector } from '@/components/transaction-inspector';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    incomeSourceOptions,
    movementDescription,
    movementDirectionOptions,
    movementKindFromValue,
    movementKindLabel,
    movementKindOptions,
    movementSupportsCategory,
    transferPurposeOptions,
} from '@/lib/money-movement';
import { index as reviewQueueIndex } from '@/routes/review_queue';
import { create as createStatementImport } from '@/routes/statement_imports';
import { index } from '@/routes/transactions';
import type {
    CategoryOption,
    LedgerFilters,
    LedgerTransaction,
    SelectedTransaction,
    TransactionKind,
} from '@/types';

type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    previous_page_url: string | null;
    next_page_url: string | null;
};

export type TransactionsIndexProps = {
    today: string;
    category_options: CategoryOption[];
    transactions: LedgerTransaction[];
    voided_transactions: LedgerTransaction[];
    pagination: Pagination;
    selected_transaction_id?: number | null;
    selected_transaction?: SelectedTransaction | null;
    filters: LedgerFilters;
    workspace: {
        mode: 'transactions' | 'review_queue';
    };
    errors?: Record<string, string>;
};

function workspaceQuery(
    filters: LedgerFilters,
    selected?: number,
    page?: number,
    inspector?: 'closed',
) {
    return {
        search: filters.search || undefined,
        date_from: filters.date_from ?? undefined,
        date_to: filters.date_to ?? undefined,
        currency: filters.currency === 'all' ? undefined : filters.currency,
        kind: filters.kind === 'all' ? undefined : filters.kind,
        category_id: filters.category_id ?? undefined,
        category_state:
            filters.category_state === 'all'
                ? undefined
                : filters.category_state,
        review_state:
            filters.review_state === 'all' ? undefined : filters.review_state,
        refund_relationship:
            filters.refund_relationship === 'all'
                ? undefined
                : filters.refund_relationship,
        void_state:
            filters.void_state === 'all' ? undefined : filters.void_state,
        selected,
        page,
        inspector,
    };
}

function workspaceIndex(
    mode: 'transactions' | 'review_queue',
    filters?: LedgerFilters,
    selected?: number,
    page?: number,
    inspector?: 'closed',
) {
    const options = filters
        ? { query: workspaceQuery(filters, selected, page, inspector) }
        : undefined;

    return mode === 'review_queue' ? reviewQueueIndex(options) : index(options);
}

function TransactionStateForm({
    transaction,
}: {
    transaction: LedgerTransaction;
}) {
    const isVoided = transaction.voided_at !== null;
    const transactionRoute = isVoided
        ? restoreTransaction.form(transaction.id)
        : voidTransaction.form(transaction.id);
    const Icon = isVoided ? RotateCcw : CircleOff;

    return (
        <Form
            {...transactionRoute}
            options={{ preserveScroll: true }}
            className="grid justify-items-end gap-1"
        >
            {({ errors, processing }) => (
                <>
                    <Button
                        type="submit"
                        variant={isVoided ? 'secondary' : 'outline'}
                        size="sm"
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <Icon />}
                        {isVoided ? 'Restore' : 'Void'}
                    </Button>
                    <InputError message={errors.void_state} />
                </>
            )}
        </Form>
    );
}

function LedgerFiltersForm({
    filters,
    categoryOptions,
    mode,
}: {
    filters: LedgerFilters;
    categoryOptions: CategoryOption[];
    mode: 'transactions' | 'review_queue';
}) {
    const activeFilters = [
        filters.search ? `Search: ${filters.search}` : null,
        filters.date_from ? `From: ${filters.date_from}` : null,
        filters.date_to ? `To: ${filters.date_to}` : null,
        filters.currency === 'all' ? null : `Currency: ${filters.currency}`,
        filters.kind === 'all'
            ? null
            : `Kind: ${movementKindLabel(filters.kind)}`,
        filters.category_id === null
            ? null
            : `Category: ${categoryOptions.find((category) => category.id === filters.category_id)?.path ?? filters.category_id}`,
        filters.category_state === 'all'
            ? null
            : `Category state: ${filters.category_state}`,
        mode === 'review_queue' || filters.review_state === 'all'
            ? null
            : `Review: ${filters.review_state === 'outstanding' ? 'Needs review' : 'Clear'}`,
        filters.refund_relationship === 'all'
            ? null
            : `Refunds: ${filters.refund_relationship.replace('_', ' ')}`,
        mode === 'review_queue' || filters.void_state === 'all'
            ? null
            : `Ledger state: ${filters.void_state}`,
    ].filter((filter): filter is string => filter !== null);
    const hasAdvancedFilters =
        filters.category_state !== 'all' ||
        (mode === 'transactions' && filters.review_state !== 'all') ||
        filters.refund_relationship !== 'all' ||
        (mode === 'transactions' && filters.void_state !== 'all');

    return (
        <Card>
            <CardHeader className="gap-1">
                <CardTitle className="text-base">Find Transactions</CardTitle>
                <CardDescription>
                    Search by description, then narrow the current ledger state.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                {activeFilters.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg bg-muted/60 p-3">
                        <span className="text-xs font-medium text-muted-foreground">
                            Active filters
                        </span>
                        {activeFilters.map((filter) => (
                            <Badge key={filter} variant="outline">
                                {filter}
                            </Badge>
                        ))}
                        <Button asChild type="button" variant="ghost" size="sm">
                            <Link href={workspaceIndex(mode)}>Clear all</Link>
                        </Button>
                    </div>
                )}
                <Form
                    action={workspaceIndex(mode).url}
                    method="get"
                    options={{ preserveState: true, preserveScroll: true }}
                    className="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                >
                    {({ processing }) => (
                        <>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="ledger-search">
                                    Merchant or description
                                </Label>
                                <Input
                                    id="ledger-search"
                                    name="search"
                                    type="search"
                                    defaultValue={filters.search}
                                    placeholder="Search the ledger"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="filter-date-from">From</Label>
                                <Input
                                    id="filter-date-from"
                                    name="date_from"
                                    type="date"
                                    defaultValue={filters.date_from ?? ''}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="filter-date-to">To</Label>
                                <Input
                                    id="filter-date-to"
                                    name="date_to"
                                    type="date"
                                    defaultValue={filters.date_to ?? ''}
                                />
                            </div>
                            <SelectFilter
                                id="filter-currency"
                                name="currency"
                                label="Filter currency"
                                value={
                                    filters.currency === 'all'
                                        ? ''
                                        : filters.currency
                                }
                                options={[
                                    { value: '', label: 'All currencies' },
                                    { value: 'USD', label: 'USD' },
                                    { value: 'PEN', label: 'PEN' },
                                ]}
                            />
                            <SelectFilter
                                id="filter-kind"
                                name="kind"
                                label="Filter kind"
                                value={
                                    filters.kind === 'all' ? '' : filters.kind
                                }
                                options={[
                                    { value: '', label: 'All kinds' },
                                    { value: 'spending', label: 'Spending' },
                                    { value: 'refund', label: 'Refunds' },
                                    { value: 'income', label: 'Income' },
                                    { value: 'transfer', label: 'Transfers' },
                                ]}
                            />
                            <SelectFilter
                                id="filter-category"
                                name="category_id"
                                label="Filter Category"
                                value={filters.category_id?.toString() ?? ''}
                                options={[
                                    { value: '', label: 'All Categories' },
                                    ...categoryOptions.map((category) => ({
                                        value: category.id.toString(),
                                        label: category.path,
                                    })),
                                ]}
                            />
                            <details
                                className="rounded-lg border md:col-span-2 xl:col-span-4"
                                open={hasAdvancedFilters || undefined}
                            >
                                <summary className="cursor-pointer px-4 py-3 text-sm font-medium">
                                    Advanced filters
                                </summary>
                                <div className="grid gap-3 border-t p-4 md:grid-cols-2 xl:grid-cols-4">
                                    <SelectFilter
                                        id="filter-category-state"
                                        name="category_state"
                                        label="Filter Category state"
                                        value={
                                            filters.category_state === 'all'
                                                ? ''
                                                : filters.category_state
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'Any Category state',
                                            },
                                            {
                                                value: 'categorized',
                                                label: 'Categorized',
                                            },
                                            {
                                                value: 'uncategorized',
                                                label: 'Uncategorized',
                                            },
                                        ]}
                                    />
                                    {mode === 'transactions' && (
                                        <SelectFilter
                                            id="filter-review-state"
                                            name="review_state"
                                            label="Filter review state"
                                            value={
                                                filters.review_state === 'all'
                                                    ? ''
                                                    : filters.review_state
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Any review state',
                                                },
                                                {
                                                    value: 'outstanding',
                                                    label: 'Needs review',
                                                },
                                                {
                                                    value: 'clear',
                                                    label: 'Review clear',
                                                },
                                            ]}
                                        />
                                    )}
                                    <SelectFilter
                                        id="filter-refund-relationship"
                                        name="refund_relationship"
                                        label="Filter Refund relationship"
                                        value={
                                            filters.refund_relationship ===
                                            'all'
                                                ? ''
                                                : filters.refund_relationship
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'Any Refund relationship',
                                            },
                                            {
                                                value: 'linked',
                                                label: 'Linked Refunds',
                                            },
                                            {
                                                value: 'unlinked',
                                                label: 'Unlinked Refunds',
                                            },
                                            {
                                                value: 'not_applicable',
                                                label: 'Purchases',
                                            },
                                        ]}
                                    />
                                    {mode === 'transactions' && (
                                        <SelectFilter
                                            id="filter-void-state"
                                            name="void_state"
                                            label="Filter void state"
                                            value={
                                                filters.void_state === 'all'
                                                    ? ''
                                                    : filters.void_state
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Active and Voided',
                                                },
                                                {
                                                    value: 'active',
                                                    label: 'Active only',
                                                },
                                                {
                                                    value: 'voided',
                                                    label: 'Voided only',
                                                },
                                            ]}
                                        />
                                    )}
                                </div>
                            </details>
                            <div className="flex items-end gap-2 xl:col-span-2">
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Search />}
                                    Apply filters
                                </Button>
                                <Button asChild type="button" variant="ghost">
                                    <Link href={workspaceIndex(mode)}>
                                        Clear
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

function SelectFilter({
    id,
    name,
    label,
    value,
    options,
}: {
    id: string;
    name: string;
    label: string;
    value: string;
    options: ReadonlyArray<{ value: string; label: string }>;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <NativeSelect
                id={id}
                name={name}
                defaultValue={value}
                options={options}
            />
        </div>
    );
}

function RecordTransactionForm({ today }: { today: string }) {
    const [kind, setKind] = useState<TransactionKind>('spending');

    return (
        <Card>
            <CardHeader>
                <CardTitle>Record a Transaction</CardTitle>
                <CardDescription>
                    Record what the money meant and which way it moved.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...recordTransaction.form()}
                    resetOnSuccess={['amount', 'description']}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="occurred_on">
                                    Occurrence date
                                </Label>
                                <Input
                                    id="occurred_on"
                                    name="occurred_on"
                                    type="date"
                                    defaultValue={today}
                                />
                                <InputError message={errors.occurred_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="amount">Amount</Label>
                                <Input
                                    id="amount"
                                    name="amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    inputMode="decimal"
                                    placeholder="12.50"
                                />
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SelectFilter
                                    id="currency"
                                    name="currency"
                                    label="Currency"
                                    value="USD"
                                    options={[
                                        { value: 'USD', label: 'USD' },
                                        { value: 'PEN', label: 'PEN' },
                                    ]}
                                />
                                <div className="grid gap-2">
                                    <Label htmlFor="kind">Movement kind</Label>
                                    <NativeSelect
                                        id="kind"
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
                                <SelectFilter
                                    id="direction"
                                    name="direction"
                                    label="Money direction"
                                    value="debit"
                                    options={movementDirectionOptions}
                                />
                            </div>
                            {kind === 'income' && (
                                <SelectFilter
                                    id="income_source"
                                    name="income_source"
                                    label="Income source"
                                    value="salary"
                                    options={incomeSourceOptions}
                                />
                            )}
                            {kind === 'transfer' && (
                                <SelectFilter
                                    id="transfer_purpose"
                                    name="transfer_purpose"
                                    label="Transfer purpose"
                                    value="internal"
                                    options={transferPurposeOptions}
                                />
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    Merchant or short description
                                </Label>
                                <Input
                                    id="description"
                                    name="description"
                                    maxLength={255}
                                    autoComplete="off"
                                />
                                <InputError message={errors.description} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Record Transaction
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

function LedgerList({
    transactions,
    mode,
    filters,
    page,
    selectedTransactionId,
}: {
    transactions: LedgerTransaction[];
    mode: 'transactions' | 'review_queue';
    filters: LedgerFilters;
    page: number;
    selectedTransactionId?: number;
}) {
    return (
        <ul className="grid gap-3">
            {transactions.map((transaction) => {
                const isMoneyIn = transaction.direction === 'credit';
                const DirectionIcon = isMoneyIn ? ArrowDownLeft : ArrowUpRight;

                return (
                    <li
                        key={transaction.id}
                        className={`grid gap-4 rounded-lg border p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center ${selectedTransactionId === transaction.id ? 'border-primary/40 bg-primary/5' : 'hover:bg-muted/40'}`}
                    >
                        <div className="grid min-w-0 gap-2">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <p className="min-w-0 font-medium break-words">
                                    {transaction.description}
                                </p>
                                <p
                                    className={`font-semibold whitespace-nowrap tabular-nums ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                                >
                                    {isMoneyIn ? '+' : '−'}
                                    {formatMinorUnits(
                                        transaction.amount_minor,
                                        transaction.currency,
                                    )}
                                </p>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {transaction.occurred_on}
                            </p>
                            <div className="flex flex-wrap gap-1">
                                <Badge
                                    variant={
                                        isMoneyIn ? 'secondary' : 'outline'
                                    }
                                >
                                    <DirectionIcon />
                                    {movementDescription({
                                        kind: transaction.kind,
                                        transferPurpose:
                                            transaction.transfer_purpose,
                                    })}
                                </Badge>
                                {transaction.category ? (
                                    <Badge variant="outline">
                                        {transaction.category.name}
                                    </Badge>
                                ) : movementSupportsCategory(
                                      transaction.kind,
                                  ) ? (
                                    <Badge variant="outline">
                                        Uncategorized
                                    </Badge>
                                ) : null}
                                {transaction.review_state === 'outstanding' && (
                                    <Badge variant="secondary">
                                        Needs review
                                    </Badge>
                                )}
                                {transaction.voided_at !== null && (
                                    <Badge variant="secondary">
                                        <CircleOff /> Voided
                                    </Badge>
                                )}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2 sm:flex-col sm:items-stretch">
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={workspaceIndex(
                                        mode,
                                        filters,
                                        transaction.id,
                                        page,
                                    )}
                                    preserveScroll
                                    preserveState
                                >
                                    Inspect
                                </Link>
                            </Button>
                            <TransactionStateForm transaction={transaction} />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function PaginationControls({ pagination }: { pagination: Pagination }) {
    if (pagination.last_page === 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4 border-t pt-4">
            <p className="text-sm text-muted-foreground">
                {pagination.from}–{pagination.to} of {pagination.total}
            </p>
            <div className="flex gap-2">
                <Button
                    asChild={pagination.previous_page_url !== null}
                    variant="outline"
                    size="sm"
                    disabled={pagination.previous_page_url === null}
                >
                    {pagination.previous_page_url ? (
                        <Link
                            href={pagination.previous_page_url}
                            preserveScroll
                        >
                            Previous
                        </Link>
                    ) : (
                        <span>Previous</span>
                    )}
                </Button>
                <Button
                    asChild={pagination.next_page_url !== null}
                    variant="outline"
                    size="sm"
                    disabled={pagination.next_page_url === null}
                >
                    {pagination.next_page_url ? (
                        <Link href={pagination.next_page_url} preserveScroll>
                            Next
                        </Link>
                    ) : (
                        <span>Next</span>
                    )}
                </Button>
            </div>
        </div>
    );
}

export default function TransactionsIndex({
    today,
    category_options,
    transactions,
    voided_transactions,
    pagination,
    selected_transaction_id,
    selected_transaction,
    filters,
    workspace,
    errors = {},
}: TransactionsIndexProps) {
    const page = usePage<{
        flash: { transaction_state_error?: string };
    }>();
    const isReviewQueue = workspace.mode === 'review_queue';
    const selectedIdValue = new URLSearchParams(
        page.url.split('?')[1] ?? '',
    ).get('selected');
    const selectedTransactionId = selectedIdValue
        ? Number(selectedIdValue)
        : (selected_transaction_id ?? undefined);
    const ledgerRows = [...transactions, ...voided_transactions].sort(
        (first, second) =>
            second.occurred_on.localeCompare(first.occurred_on) ||
            second.id - first.id,
    );
    const transactionStateError = page.props.flash?.transaction_state_error;
    const stateErrors = [transactionStateError, errors.void_state].filter(
        (error): error is string => Boolean(error),
    );

    function handleInspectorOpenChange(open: boolean) {
        if (open) {
            return;
        }

        router.get(
            workspaceIndex(
                workspace.mode,
                filters,
                undefined,
                pagination.current_page,
                isReviewQueue ? 'closed' : undefined,
            ).url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <>
            <Head title={isReviewQueue ? 'Review Queue' : 'Transactions'} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {isReviewQueue ? 'Review Queue' : 'Transactions'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {isReviewQueue
                                ? 'Current Uncategorized and uncertain Transaction or Line Item state.'
                                : 'A focused ledger for finding, reviewing, and editing current Transactions.'}
                        </p>
                    </div>
                    {isReviewQueue ? (
                        <Badge variant="outline" className="w-fit">
                            <ListChecks /> {pagination.total}{' '}
                            {pagination.total === 1
                                ? 'Transaction'
                                : 'Transactions'}
                        </Badge>
                    ) : (
                        <Button asChild variant="outline">
                            <Link href={createStatementImport()}>
                                <FileUp /> Import statement
                            </Link>
                        </Button>
                    )}
                </div>

                {stateErrors.length > 0 && (
                    <AlertError
                        title="Transaction state was not changed."
                        errors={stateErrors}
                    />
                )}

                <LedgerFiltersForm
                    filters={filters}
                    categoryOptions={category_options}
                    mode={workspace.mode}
                />

                <div
                    className={`grid items-start gap-6 ${isReviewQueue ? '' : 'xl:grid-cols-[minmax(20rem,24rem)_minmax(0,1fr)]'}`}
                >
                    {!isReviewQueue && <RecordTransactionForm today={today} />}
                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>
                                {isReviewQueue
                                    ? 'Transactions awaiting review'
                                    : 'Ledger'}
                            </CardTitle>
                            <CardDescription>
                                {pagination.total} matching current-state{' '}
                                {pagination.total === 1
                                    ? 'Transaction'
                                    : 'Transactions'}
                                .
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {ledgerRows.length === 0 ? (
                                <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-6 text-center">
                                    <ReceiptText className="size-8 text-muted-foreground" />
                                    <div className="grid gap-1">
                                        <p className="font-medium">
                                            {isReviewQueue
                                                ? 'Review Queue is clear'
                                                : 'No Transactions yet'}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {isReviewQueue
                                                ? 'No current Transaction or Line Item fields need review.'
                                                : 'Adjust the filters or record a new money movement.'}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <LedgerList
                                    transactions={ledgerRows}
                                    mode={workspace.mode}
                                    filters={filters}
                                    page={pagination.current_page}
                                    selectedTransactionId={
                                        selectedTransactionId
                                    }
                                />
                            )}
                            <PaginationControls pagination={pagination} />
                        </CardContent>
                    </Card>
                </div>
            </div>

            {selectedTransactionId !== undefined && (
                <Deferred
                    data="selected_transaction"
                    fallback={
                        <div className="fixed inset-y-0 right-0 z-50 grid w-full max-w-2xl place-items-center border-l bg-background/95">
                            <Spinner className="size-6" />
                        </div>
                    }
                >
                    <TransactionInspector
                        transaction={selected_transaction ?? null}
                        categoryOptions={category_options}
                        onOpenChange={handleInspectorOpenChange}
                    />
                </Deferred>
            )}
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Transactions',
            href: index(),
        },
    ],
};
