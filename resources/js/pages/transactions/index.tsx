import { Deferred, Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CircleOff,
    ListChecks,
    ReceiptText,
    RotateCcw,
    Search,
} from 'lucide-react';
import { destroy as reopenSuspectedDuplicate } from '@/actions/App/Http/Controllers/SuspectedDuplicateResolutionController';
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
import { index as reviewQueueIndex } from '@/routes/review_queue';
import { index } from '@/routes/transactions';
import type {
    CategoryOption,
    Currency,
    LedgerCategory,
    LedgerFilters,
    SelectedTransaction,
    TransactionKind,
} from '@/types';

type LedgerTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    revision: number;
    original_purchase: {
        id: number;
        merchant_description: string;
    } | null;
    category: LedgerCategory | null;
    review_state: 'outstanding' | 'clear';
    review_field_count: number;
    refund_relationship_review_count: number;
    duplicate_status: 'suspected' | 'resolved' | 'none';
    voided_at: string | null;
    duplicate_resolution: {
        id: number;
        revision: number;
        first_transaction_revision: number;
        second_transaction_revision: number;
        reopen_idempotency_key: string;
    } | null;
    state_change_idempotency_key: string;
};

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
        duplicate_status:
            filters.duplicate_status === 'all'
                ? undefined
                : filters.duplicate_status,
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
                    <input
                        type="hidden"
                        name="expected_revision"
                        value={transaction.revision}
                    />
                    <input
                        type="hidden"
                        name="idempotency_key"
                        value={transaction.state_change_idempotency_key}
                    />
                    <Button
                        type="submit"
                        variant={isVoided ? 'secondary' : 'outline'}
                        size="sm"
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <Icon />}
                        {isVoided ? 'Restore' : 'Void'}
                    </Button>
                    <InputError
                        message={
                            errors.expected_revision ??
                            errors.idempotency_key ??
                            errors.void_state
                        }
                    />
                </>
            )}
        </Form>
    );
}

function ReopenSuspectedDuplicateForm({
    transaction,
}: {
    transaction: LedgerTransaction;
}) {
    const duplicateResolution = transaction.duplicate_resolution;

    if (duplicateResolution === null) {
        return null;
    }

    return (
        <Form
            {...reopenSuspectedDuplicate.form(duplicateResolution.id)}
            options={{ preserveScroll: true }}
            className="grid justify-items-end gap-1"
        >
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="expected_suspected_duplicate_revision"
                        value={duplicateResolution.revision}
                    />
                    <input
                        type="hidden"
                        name="expected_first_transaction_revision"
                        value={duplicateResolution.first_transaction_revision}
                    />
                    <input
                        type="hidden"
                        name="expected_second_transaction_revision"
                        value={duplicateResolution.second_transaction_revision}
                    />
                    <input
                        type="hidden"
                        name="idempotency_key"
                        value={duplicateResolution.reopen_idempotency_key}
                    />
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <RotateCcw />}
                        Reopen pair
                    </Button>
                    <InputError
                        message={errors.suspected_duplicate_resolution}
                    />
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
    return (
        <Card>
            <CardHeader className="gap-1">
                <CardTitle className="text-base">Find Transactions</CardTitle>
                <CardDescription>
                    Search by description, then narrow the current ledger state.
                </CardDescription>
            </CardHeader>
            <CardContent>
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
                                    { value: 'purchase', label: 'Purchases' },
                                    { value: 'refund', label: 'Refunds' },
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
                                    { value: '', label: 'Any Category state' },
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
                                    filters.refund_relationship === 'all'
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
                            <SelectFilter
                                id="filter-duplicate-status"
                                name="duplicate_status"
                                label="Filter duplicate status"
                                value={
                                    filters.duplicate_status === 'all'
                                        ? ''
                                        : filters.duplicate_status
                                }
                                options={[
                                    {
                                        value: '',
                                        label: 'Any duplicate status',
                                    },
                                    {
                                        value: 'suspected',
                                        label: 'Suspected duplicates',
                                    },
                                    {
                                        value: 'resolved',
                                        label: 'Resolved duplicates',
                                    },
                                    {
                                        value: 'none',
                                        label: 'No duplicate relationship',
                                    },
                                ]}
                            />
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
                                    { value: '', label: 'Active and Voided' },
                                    { value: 'active', label: 'Active only' },
                                    { value: 'voided', label: 'Voided only' },
                                ]}
                            />
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
    options: Array<{ value: string; label: string }>;
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
    return (
        <Card>
            <CardHeader>
                <CardTitle>Record a Transaction</CardTitle>
                <CardDescription>
                    Add a purchase or Refund in its original currency.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...recordTransaction.form()}
                    resetOnSuccess={['amount_minor', 'merchant_description']}
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
                                <Label htmlFor="amount_minor">
                                    Amount in minor units
                                </Label>
                                <Input
                                    id="amount_minor"
                                    name="amount_minor"
                                    type="number"
                                    min="1"
                                    step="1"
                                    inputMode="numeric"
                                    placeholder="1250"
                                />
                                <InputError message={errors.amount_minor} />
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
                                <SelectFilter
                                    id="kind"
                                    name="kind"
                                    label="Transaction kind"
                                    value="purchase"
                                    options={[
                                        {
                                            value: 'purchase',
                                            label: 'Purchase',
                                        },
                                        { value: 'refund', label: 'Refund' },
                                    ]}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="merchant_description">
                                    Merchant or short description
                                </Label>
                                <Input
                                    id="merchant_description"
                                    name="merchant_description"
                                    maxLength={255}
                                    autoComplete="off"
                                />
                                <InputError
                                    message={errors.merchant_description}
                                />
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

function LedgerTable({
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
        <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-sm">
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        <th className="pb-3 font-medium">Date</th>
                        <th className="pb-3 font-medium">
                            Merchant or description
                        </th>
                        <th className="pb-3 font-medium">Kind</th>
                        <th className="pb-3 text-right font-medium">Amount</th>
                        <th className="pb-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {transactions.map((transaction) => {
                        const isRefund = transaction.kind === 'refund';
                        const isVoided = transaction.voided_at !== null;
                        const KindIcon = isRefund
                            ? ArrowDownLeft
                            : ArrowUpRight;

                        return (
                            <tr
                                key={transaction.id}
                                className={`border-b last:border-0 ${selectedTransactionId === transaction.id ? 'bg-primary/5' : 'hover:bg-muted/40'}`}
                            >
                                <td className="py-4 pr-4 whitespace-nowrap text-muted-foreground">
                                    {transaction.occurred_on}
                                </td>
                                <td className="py-4 pr-4">
                                    <p className="font-medium">
                                        {transaction.merchant_description}
                                    </p>
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {transaction.category ? (
                                            <span className="text-xs text-muted-foreground">
                                                {transaction.category.name}
                                            </span>
                                        ) : (
                                            <Badge variant="outline">
                                                Uncategorized
                                            </Badge>
                                        )}
                                        {transaction.review_state ===
                                            'outstanding' && (
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
                                </td>
                                <td className="py-4 pr-4">
                                    <Badge
                                        variant={
                                            isRefund ? 'secondary' : 'outline'
                                        }
                                    >
                                        <KindIcon />
                                        {isRefund ? 'Refund' : 'Purchase'}
                                    </Badge>
                                </td>
                                <td
                                    className={`py-4 text-right font-medium whitespace-nowrap tabular-nums ${isRefund ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                                >
                                    {isRefund ? '−' : ''}
                                    {formatMinorUnits(
                                        transaction.amount_minor,
                                        transaction.currency,
                                    )}
                                </td>
                                <td className="py-4 pl-4">
                                    <div className="grid justify-items-end gap-2">
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
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
                                        {isVoided &&
                                        transaction.duplicate_resolution !==
                                            null ? (
                                            <ReopenSuspectedDuplicateForm
                                                transaction={transaction}
                                            />
                                        ) : (
                                            <TransactionStateForm
                                                transaction={transaction}
                                            />
                                        )}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
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
    const stateErrors = [
        transactionStateError,
        errors.expected_revision,
        errors.idempotency_key,
        errors.void_state,
    ].filter((error): error is string => Boolean(error));

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
                    {isReviewQueue && (
                        <Badge variant="outline" className="w-fit">
                            <ListChecks /> {pagination.total}{' '}
                            {pagination.total === 1
                                ? 'Transaction'
                                : 'Transactions'}
                        </Badge>
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
                                                : 'Adjust the filters or record a new purchase or Refund.'}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <LedgerTable
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
