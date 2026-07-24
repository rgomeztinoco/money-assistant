import { Form, Head, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CircleOff,
    Link2,
    ReceiptText,
    RotateCcw,
    Tags,
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
import { index } from '@/routes/transactions';

type Currency = 'USD' | 'PEN';
type TransactionKind = 'purchase' | 'refund';
type LedgerCategory = {
    id: number;
    name: string;
    provenance: 'owner' | 'linked_refund' | 'learned_rule' | 'ai';
};

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

type TransactionsIndexProps = {
    today: string;
    totals: Record<Currency, string>;
    category_totals: Array<{
        category: Pick<LedgerCategory, 'id' | 'name'>;
        totals: Record<Currency, string>;
    }>;
    purchase_options: PurchaseOption[];
    transactions: LedgerTransaction[];
    voided_transactions: VoidedLedgerTransaction[];
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
    label,
    defaultValue,
    error,
    options,
}: {
    id: string;
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
                name={id}
                defaultValue={defaultValue}
                aria-invalid={error ? true : undefined}
                options={options}
            />
            <InputError message={error} />
        </div>
    );
}

function formatMinorUnits(amountMinor: string, currency: Currency): string {
    const isNegative = amountMinor.startsWith('-');
    const digits = (isNegative ? amountMinor.slice(1) : amountMinor).padStart(
        3,
        '0',
    );
    const integerPart = digits
        .slice(0, -2)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fractionalPart = digits.slice(-2);
    const symbol = currency === 'USD' ? '$' : 'S/';

    return `${isNegative ? '−' : ''}${symbol} ${integerPart}.${fractionalPart}`;
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
        <Form {...transactionRoute} className="grid justify-items-end gap-1">
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
        <Form {...linkRefund.form(refund.id)} className="grid min-w-52 gap-1.5">
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

function LedgerTable({
    transactions,
    purchases,
    operation,
}: {
    transactions: Array<LedgerTransaction | VoidedLedgerTransaction>;
    purchases: PurchaseOption[];
    operation: 'void' | 'restore';
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
                                className="border-b last:border-0"
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

export default function TransactionsIndex({
    today,
    totals,
    category_totals,
    purchase_options,
    transactions,
    voided_transactions,
    errors = {},
}: TransactionsIndexProps) {
    const { flash } = usePage();
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

    return (
        <>
            <Head title="Transactions" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Transactions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Record confirmed purchases and Refunds in their original
                        currency.
                    </p>
                </div>

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

                <div className="grid items-start gap-6 xl:grid-cols-[minmax(20rem,24rem)_minmax(0,1fr)]">
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
                                                message={errors.amount_minor}
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
                                                Merchant or short description
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

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Active ledger</CardTitle>
                            <CardDescription>
                                Latest 100 active Transactions. Voided
                                Transactions are excluded from this ledger and
                                its totals.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {transactions.length === 0 ? (
                                <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-6 text-center">
                                    <ReceiptText className="size-8 text-muted-foreground" />
                                    <div className="grid gap-1">
                                        <p className="font-medium">
                                            No Transactions yet
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Record the first purchase or Refund
                                            to start the ledger.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <LedgerTable
                                    transactions={transactions}
                                    purchases={purchase_options}
                                    operation="void"
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Voided Transactions</CardTitle>
                        <CardDescription>
                            Retained for traceability, excluded from active
                            ledger results and spending totals, and available to
                            restore.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {voided_transactions.length === 0 ? (
                            <div className="flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-6 text-center">
                                <CircleOff className="size-7 text-muted-foreground" />
                                <p className="font-medium">
                                    No Voided Transactions
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Transactions you void will remain visible
                                    here for restoration.
                                </p>
                            </div>
                        ) : (
                            <LedgerTable
                                transactions={voided_transactions}
                                purchases={purchase_options}
                                operation="restore"
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
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
