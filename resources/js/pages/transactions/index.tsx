import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CircleOff,
    Link2,
    ListChecks,
    ReceiptText,
    RotateCcw,
    Search,
    Tags,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { destroy as reopenSuspectedDuplicate } from '@/actions/App/Http/Controllers/SuspectedDuplicateResolutionController';
import { store as recordTransaction } from '@/actions/App/Http/Controllers/TransactionController';
import { store as linkRefund } from '@/actions/App/Http/Controllers/TransactionRefundLinkController';
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
    Currency,
    CategoryOption,
    LedgerCategory,
    LedgerFilters,
    SelectedTransaction,
    TransactionKind,
} from '@/types';

type PurchaseOption = {
    id: number;
    occurred_on: string;
    merchant_description: string;
    currency: Currency;
};

type LedgerTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    confirmed_at: string;
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
    state_change_idempotency_key: string;
};

type VoidedLedgerTransaction = LedgerTransaction & {
    voided_at: string;
    duplicate_resolution: {
        id: number;
        revision: number;
        first_transaction_revision: number;
        second_transaction_revision: number;
        reopen_idempotency_key: string;
    } | null;
};

export type TransactionsIndexProps = {
    today: string;
    totals: Record<Currency, string>;
    category_totals: Array<{
        category: Pick<LedgerCategory, 'id' | 'name'>;
        totals: Record<Currency, string>;
    }>;
    category_options: CategoryOption[];
    purchase_options: PurchaseOption[];
    transactions: LedgerTransaction[];
    voided_transactions: VoidedLedgerTransaction[];
    workspace_transactions?: LedgerTransaction[];
    workspace_voided_transactions?: VoidedLedgerTransaction[];
    selected_transaction: SelectedTransaction | null;
    filters: LedgerFilters;
    workspace: {
        mode: 'transactions' | 'review_queue';
    };
    unresolved_field_count?: number;
    unresolved_refund_relationship_count?: number;
    unresolved_suspected_duplicate_count?: number;
    stale_transaction?: {
        id: number;
        revision: number;
        amount_minor: string;
        currency: Currency;
        kind: TransactionKind;
        merchant_description: string;
        occurred_on: string;
        provisional_fields: string[];
    } | null;
    errors?: Record<string, string>;
};

type TransactionKindPresentation = {
    label: string;
    icon: LucideIcon;
    badgeVariant: 'outline' | 'secondary';
    amountPrefix: string;
    amountClassName: string;
};

const transactionKindPresentations: Record<
    TransactionKind,
    TransactionKindPresentation
> = {
    purchase: {
        label: 'Purchase',
        icon: ArrowUpRight,
        badgeVariant: 'outline',
        amountPrefix: '',
        amountClassName: '',
    },
    refund: {
        label: 'Refund',
        icon: ArrowDownLeft,
        badgeVariant: 'secondary',
        amountPrefix: '−',
        amountClassName: 'text-emerald-700 dark:text-emerald-400',
    },
};

function NativeSelectField({
    id,
    name = id,
    label,
    defaultValue,
    error,
    options,
}: {
    id: string;
    name?: string;
    label: string;
    defaultValue: string;
    error?: string;
    options: ReadonlyArray<{ value: string; label: string }>;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <NativeSelect
                id={id}
                name={name}
                defaultValue={defaultValue}
                aria-invalid={error ? true : undefined}
                options={options}
            />
            <InputError message={error} />
        </div>
    );
}

function transactionAmount(transaction: LedgerTransaction): string {
    const formattedAmount = formatMinorUnits(
        transaction.amount_minor,
        transaction.currency,
    );

    return `${transactionKindPresentations[transaction.kind].amountPrefix}${formattedAmount}`;
}

function TransactionVoidStateForm({
    transaction,
    operation,
}: {
    transaction: LedgerTransaction;
    operation: 'void' | 'restore';
}) {
    const isVoid = operation === 'void';
    const transactionRoute = isVoid
        ? voidTransaction.form(transaction.id)
        : restoreTransaction.form(transaction.id);
    const Icon = isVoid ? CircleOff : RotateCcw;

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
                        variant={isVoid ? 'outline' : 'secondary'}
                        size="sm"
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <Icon />}
                        {isVoid ? 'Void' : 'Restore'}
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

function RefundLinkForm({
    refund,
    purchases,
}: {
    refund: LedgerTransaction;
    purchases: PurchaseOption[];
}) {
    const selectId = `refund-${refund.id}-purchase`;

    return (
        <Form
            {...linkRefund.form(refund.id)}
            options={{ preserveScroll: true }}
            className="grid min-w-52 gap-1.5"
        >
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="expected_revision"
                        value={refund.revision}
                    />
                    <Label htmlFor={selectId} className="sr-only">
                        Original purchase for {refund.merchant_description}
                    </Label>
                    <div className="flex items-center gap-2">
                        <NativeSelect
                            id={selectId}
                            name="purchase_id"
                            required
                            defaultValue=""
                            aria-invalid={errors.purchase_id ? true : undefined}
                            options={[
                                {
                                    value: '',
                                    label: 'Select original purchase',
                                },
                                ...purchases.map((purchase) => ({
                                    value: purchase.id.toString(),
                                    label: `${purchase.occurred_on} · ${purchase.merchant_description}`,
                                })),
                            ]}
                        />
                        <Button
                            type="submit"
                            variant="secondary"
                            size="sm"
                            disabled={processing}
                        >
                            {processing ? <Spinner /> : <Link2 />}
                            Link Refund
                        </Button>
                    </div>
                    <InputError
                        message={errors.purchase_id ?? errors.refund_link}
                    />
                </>
            )}
        </Form>
    );
}

function ReopenSuspectedDuplicateForm({
    transaction,
}: {
    transaction: VoidedLedgerTransaction;
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

function workspaceQuery(filters: LedgerFilters, selected?: number) {
    return {
        search: filters.search || undefined,
        date_from: filters.date_from ?? undefined,
        date_to: filters.date_to ?? undefined,
        currency: filters.currency === 'all' ? undefined : filters.currency,
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
    };
}

function workspaceIndex(
    mode: 'transactions' | 'review_queue',
    filters?: LedgerFilters,
    selected?: number,
    inspector?: 'closed',
) {
    const options = filters
        ? {
              query: {
                  ...workspaceQuery(filters, selected),
                  inspector,
              },
          }
        : undefined;

    return mode === 'review_queue' ? reviewQueueIndex(options) : index(options);
}

function LedgerTable({
    transactions,
    purchases,
    operation,
    mode,
    filters,
    selectedTransactionId,
}: {
    transactions: Array<LedgerTransaction | VoidedLedgerTransaction>;
    purchases: PurchaseOption[];
    operation: 'void' | 'restore';
    mode: 'transactions' | 'review_queue';
    filters: LedgerFilters;
    selectedTransactionId?: number;
}) {
    const showsVoidedState = operation === 'restore';

    return (
        <div className="overflow-x-auto">
            <table
                className={`w-full text-sm ${showsVoidedState ? 'min-w-[48rem]' : 'min-w-[44rem]'}`}
            >
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        <th className="pb-3 font-medium">Date</th>
                        <th className="pb-3 font-medium">
                            Merchant or description
                        </th>
                        <th className="pb-3 font-medium">Kind</th>
                        {showsVoidedState && (
                            <th className="pb-3 font-medium">State</th>
                        )}
                        <th className="pb-3 text-right font-medium">Amount</th>
                        <th className="pb-3 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    {transactions.map((transaction) => {
                        const presentation =
                            transactionKindPresentations[transaction.kind];
                        const KindIcon = presentation.icon;

                        return (
                            <tr
                                key={transaction.id}
                                className={`border-b transition-colors last:border-0 ${selectedTransactionId === transaction.id ? 'bg-primary/5' : 'hover:bg-muted/40'}`}
                            >
                                <td className="py-4 pr-4 whitespace-nowrap text-muted-foreground">
                                    {transaction.occurred_on}
                                </td>
                                <td className="py-4 pr-4">
                                    <p className="font-medium">
                                        {transaction.merchant_description}
                                    </p>
                                    {'voided_at' in transaction && (
                                        <p className="text-xs text-muted-foreground">
                                            Voided{' '}
                                            {transaction.voided_at.slice(0, 10)}
                                        </p>
                                    )}
                                    {transaction.original_purchase && (
                                        <p className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Link2 className="size-3" />
                                            Linked to{' '}
                                            {
                                                transaction.original_purchase
                                                    .merchant_description
                                            }
                                        </p>
                                    )}
                                    {transaction.category && (
                                        <p className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Tags className="size-3" />
                                            {transaction.category.name}
                                            {transaction.category.provenance ===
                                                'linked_refund' &&
                                                ' · from linked purchase'}
                                        </p>
                                    )}
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {transaction.review_state ===
                                            'outstanding' && (
                                            <Badge variant="secondary">
                                                Needs review
                                            </Badge>
                                        )}
                                        {transaction.duplicate_status !==
                                            'none' && (
                                            <Badge variant="outline">
                                                {transaction.duplicate_status ===
                                                'suspected'
                                                    ? 'Suspected Duplicate'
                                                    : 'Duplicate resolved'}
                                            </Badge>
                                        )}
                                    </div>
                                </td>
                                <td className="py-4 pr-4">
                                    <Badge variant={presentation.badgeVariant}>
                                        <KindIcon />
                                        {presentation.label}
                                    </Badge>
                                </td>
                                {showsVoidedState && (
                                    <td className="py-4 pr-4">
                                        <Badge variant="secondary">
                                            <CircleOff />
                                            Voided
                                        </Badge>
                                    </td>
                                )}
                                <td
                                    className={`py-4 text-right font-medium whitespace-nowrap tabular-nums ${presentation.amountClassName}`}
                                >
                                    {transactionAmount(transaction)}
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
                                                )}
                                                preserveScroll
                                                preserveState
                                            >
                                                Inspect
                                            </Link>
                                        </Button>
                                        {operation === 'void' &&
                                            transaction.kind === 'refund' &&
                                            transaction.original_purchase ===
                                                null &&
                                            purchases.some(
                                                (purchase) =>
                                                    purchase.currency ===
                                                    transaction.currency,
                                            ) && (
                                                <RefundLinkForm
                                                    refund={transaction}
                                                    purchases={purchases.filter(
                                                        (purchase) =>
                                                            purchase.currency ===
                                                            transaction.currency,
                                                    )}
                                                />
                                            )}
                                        {operation === 'restore' &&
                                        'duplicate_resolution' in transaction &&
                                        transaction.duplicate_resolution !==
                                            null ? (
                                            <ReopenSuspectedDuplicateForm
                                                transaction={transaction}
                                            />
                                        ) : (
                                            <TransactionVoidStateForm
                                                transaction={transaction}
                                                operation={operation}
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

function LedgerFiltersForm({
    filters,
    mode,
}: {
    filters: LedgerFilters;
    mode: 'transactions' | 'review_queue';
}) {
    return (
        <Card>
            <CardHeader className="gap-1">
                <CardTitle className="text-base">Find Transactions</CardTitle>
                <CardDescription>
                    Search the ledger, then combine only the states you need.
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
                            <NativeSelectField
                                id="filter-currency"
                                name="currency"
                                label="Filter currency"
                                defaultValue={
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
                            <NativeSelectField
                                id="filter-category-state"
                                name="category_state"
                                label="Filter Category state"
                                defaultValue={
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
                            {mode === 'transactions' ? (
                                <NativeSelectField
                                    id="filter-review-state"
                                    name="review_state"
                                    label="Filter review state"
                                    defaultValue={
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
                            ) : (
                                <input
                                    type="hidden"
                                    name="review_state"
                                    value="outstanding"
                                />
                            )}
                            <NativeSelectField
                                id="filter-refund-relationship"
                                name="refund_relationship"
                                label="Filter Refund relationship"
                                defaultValue={
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
                                        label: 'Not a Refund',
                                    },
                                ]}
                            />
                            {mode === 'transactions' ? (
                                <NativeSelectField
                                    id="filter-void-state"
                                    name="void_state"
                                    label="Filter void state"
                                    defaultValue={
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
                            ) : (
                                <input
                                    type="hidden"
                                    name="void_state"
                                    value="active"
                                />
                            )}
                            <NativeSelectField
                                id="filter-duplicate-status"
                                name="duplicate_status"
                                label="Filter duplicate status"
                                defaultValue={
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
                                        label: 'Suspected Duplicate',
                                    },
                                    {
                                        value: 'resolved',
                                        label: 'Resolved pair',
                                    },
                                    {
                                        value: 'none',
                                        label: 'No duplicate relationship',
                                    },
                                ]}
                            />
                            <div className="flex items-end gap-2 xl:col-span-3 xl:justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Search />}
                                    Apply filters
                                </Button>
                                <Button asChild type="button" variant="outline">
                                    <Link
                                        href={workspaceIndex(mode)}
                                        preserveScroll
                                    >
                                        <X /> Clear
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

export default function TransactionsIndex({
    today,
    totals,
    category_totals,
    category_options,
    purchase_options,
    transactions,
    voided_transactions,
    workspace_transactions,
    workspace_voided_transactions,
    selected_transaction,
    filters,
    workspace,
    unresolved_field_count = 0,
    unresolved_refund_relationship_count = 0,
    unresolved_suspected_duplicate_count = 0,
    stale_transaction = null,
    errors = {},
}: TransactionsIndexProps) {
    const { flash } = usePage();
    const isReviewQueue = workspace.mode === 'review_queue';
    const visibleTransactions = workspace_transactions ?? transactions;
    const visibleVoidedTransactions =
        workspace_voided_transactions ?? voided_transactions;
    const outstandingReviewCount =
        unresolved_field_count +
        unresolved_refund_relationship_count +
        unresolved_suspected_duplicate_count;
    const transactionStateError = flash.transaction_state_error as
        string | undefined;
    const refundLinkError = flash.refund_link_error as string | undefined;
    const voidStateErrors = [
        transactionStateError,
        errors.expected_revision,
        errors.idempotency_key,
        errors.void_state,
    ].filter((error): error is string => Boolean(error));
    const refundLinkErrors = [
        refundLinkError,
        errors.purchase_id,
        errors.refund_link,
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
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {isReviewQueue ? 'Review Queue' : 'Transactions'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {isReviewQueue
                                ? 'The outstanding-work preset of the ledger. Review uncertain details without delaying spending totals.'
                                : 'Search confirmed purchases and Refunds, then inspect their complete ledger context.'}
                        </p>
                    </div>
                    {isReviewQueue && (
                        <Badge variant="outline" className="w-fit">
                            <ListChecks />
                            {outstandingReviewCount}{' '}
                            {outstandingReviewCount === 1
                                ? 'review'
                                : 'reviews'}
                        </Badge>
                    )}
                </div>

                {stale_transaction && (
                    <Card className="border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/20">
                        <CardHeader className="gap-2">
                            <CardTitle className="text-base">
                                Transaction changed during review
                            </CardTitle>
                            <CardDescription>
                                Your older update was not applied. This is the
                                current confirmed state at revision{' '}
                                {stale_transaction.revision}.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm">
                            <p className="font-medium">
                                {stale_transaction.merchant_description}
                            </p>
                            <p className="text-muted-foreground">
                                {stale_transaction.provisional_fields.length}{' '}
                                {stale_transaction.provisional_fields.length ===
                                1
                                    ? 'field remains'
                                    : 'fields remain'}{' '}
                                for review.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {voidStateErrors.length > 0 && (
                    <AlertError
                        title="Transaction state was not changed."
                        errors={voidStateErrors}
                    />
                )}

                {refundLinkErrors.length > 0 && (
                    <AlertError
                        title="Refund was not linked."
                        errors={refundLinkErrors}
                    />
                )}

                <LedgerFiltersForm filters={filters} mode={workspace.mode} />

                <div className="grid gap-4 sm:grid-cols-2">
                    {(['USD', 'PEN'] as const).map((currency) => (
                        <Card key={currency} className="gap-3">
                            <CardHeader>
                                <CardDescription>
                                    {currency} net spending
                                </CardDescription>
                                <CardTitle
                                    className="text-3xl tabular-nums"
                                    data-test={`total-${currency.toLowerCase()}`}
                                >
                                    {formatMinorUnits(
                                        totals[currency],
                                        currency,
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-xs text-muted-foreground">
                                    Purchases minus Refunds · {totals[currency]}{' '}
                                    minor units
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {category_totals.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Category totals</CardTitle>
                            <CardDescription>
                                Net totals include linked and unlinked Refunds;
                                negative totals remain visible.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {category_totals.map(({ category, totals }) => (
                                <div
                                    key={category.id}
                                    className="grid gap-2 rounded-lg border p-4"
                                >
                                    <p className="font-medium">
                                        {category.name}
                                    </p>
                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm tabular-nums">
                                        <span>
                                            {formatMinorUnits(
                                                totals.USD,
                                                'USD',
                                            )}
                                        </span>
                                        <span>
                                            {formatMinorUnits(
                                                totals.PEN,
                                                'PEN',
                                            )}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <div
                    className={`grid items-start gap-6 ${isReviewQueue ? '' : 'xl:grid-cols-[minmax(20rem,24rem)_minmax(0,1fr)]'}`}
                >
                    {!isReviewQueue && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Record a Transaction</CardTitle>
                                <CardDescription>
                                    Saved entries are confirmed and included in
                                    totals immediately.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Form
                                    {...recordTransaction.form()}
                                    resetOnSuccess={[
                                        'amount_minor',
                                        'merchant_description',
                                    ]}
                                    className="grid gap-5"
                                >
                                    {({
                                        errors,
                                        processing,
                                        recentlySuccessful,
                                    }) => (
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
                                                    aria-invalid={
                                                        errors.occurred_on
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                                <InputError
                                                    message={errors.occurred_on}
                                                />
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
                                                    aria-describedby="amount_minor_help"
                                                    aria-invalid={
                                                        errors.amount_minor
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                                <p
                                                    id="amount_minor_help"
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    Enter 1250 for 12.50.
                                                </p>
                                                <InputError
                                                    message={
                                                        errors.amount_minor
                                                    }
                                                />
                                            </div>

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <NativeSelectField
                                                    id="currency"
                                                    label="Currency"
                                                    defaultValue="USD"
                                                    error={errors.currency}
                                                    options={[
                                                        {
                                                            value: 'USD',
                                                            label: 'USD',
                                                        },
                                                        {
                                                            value: 'PEN',
                                                            label: 'PEN',
                                                        },
                                                    ]}
                                                />

                                                <NativeSelectField
                                                    id="kind"
                                                    label="Transaction kind"
                                                    defaultValue="purchase"
                                                    error={errors.kind}
                                                    options={[
                                                        {
                                                            value: 'purchase',
                                                            label: 'Purchase',
                                                        },
                                                        {
                                                            value: 'refund',
                                                            label: 'Refund',
                                                        },
                                                    ]}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="merchant_description">
                                                    Merchant or short
                                                    description
                                                </Label>
                                                <Input
                                                    id="merchant_description"
                                                    name="merchant_description"
                                                    maxLength={255}
                                                    placeholder="Neighborhood market"
                                                    autoComplete="off"
                                                    aria-invalid={
                                                        errors.merchant_description
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors.merchant_description
                                                    }
                                                />
                                            </div>

                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                data-test="record-transaction"
                                                className="w-full"
                                            >
                                                {processing && <Spinner />}
                                                Record Transaction
                                            </Button>

                                            {recentlySuccessful && (
                                                <p
                                                    role="status"
                                                    className="text-center text-sm text-emerald-700 dark:text-emerald-400"
                                                >
                                                    Transaction recorded.
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    )}

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>
                                {isReviewQueue
                                    ? 'Transactions awaiting review'
                                    : 'Active ledger'}
                            </CardTitle>
                            <CardDescription>
                                {isReviewQueue
                                    ? 'A durable, prefiltered view of the same ledger. Select a Transaction to review its context.'
                                    : 'Latest 100 matching active Transactions. Voided Transactions remain separately traceable.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {visibleTransactions.length === 0 ? (
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
                                                ? 'Every currently recorded Transaction detail has been resolved.'
                                                : 'Adjust the filters or record a new purchase or Refund.'}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <LedgerTable
                                    transactions={visibleTransactions}
                                    purchases={purchase_options}
                                    operation="void"
                                    mode={workspace.mode}
                                    filters={filters}
                                    selectedTransactionId={
                                        selected_transaction?.id
                                    }
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>

                {!isReviewQueue && (
                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Voided Transactions</CardTitle>
                            <CardDescription>
                                Retained for traceability, excluded from active
                                ledger results and spending totals, and
                                available to restore.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {visibleVoidedTransactions.length === 0 ? (
                                <div className="flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-6 text-center">
                                    <CircleOff className="size-7 text-muted-foreground" />
                                    <p className="font-medium">
                                        No Voided Transactions
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Transactions you void will remain
                                        visible here for restoration.
                                    </p>
                                </div>
                            ) : (
                                <LedgerTable
                                    transactions={visibleVoidedTransactions}
                                    purchases={purchase_options}
                                    operation="restore"
                                    mode={workspace.mode}
                                    filters={filters}
                                    selectedTransactionId={
                                        selected_transaction?.id
                                    }
                                />
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
            <TransactionInspector
                transaction={selected_transaction}
                categoryOptions={category_options}
                onOpenChange={handleInspectorOpenChange}
            />
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
