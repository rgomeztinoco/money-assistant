import { Form, Head } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    CircleAlert,
    ListChecks,
    PencilLine,
    ScanSearch,
} from 'lucide-react';
import { useState } from 'react';
import { store as resolveSuspectedDuplicate } from '@/actions/App/Http/Controllers/SuspectedDuplicateResolutionController';
import { update } from '@/actions/App/Http/Controllers/TransactionFieldReviewController';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/review_queue';

type Currency = 'USD' | 'PEN';
type TransactionKind = 'purchase' | 'refund';
type ReviewableFieldName =
    | 'occurred_on'
    | 'amount_minor'
    | 'currency'
    | 'kind'
    | 'merchant_description';

type ReviewField = {
    name: ReviewableFieldName;
    label: string;
    value: string;
};

type ReviewTransaction = {
    id: number;
    revision: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    confirmed_at: string;
    fields: ReviewField[];
};

type RefundRelationshipReview = {
    refund: {
        id: number;
        merchant_description: string;
        amount_minor: string;
        currency: Currency;
        category_name: string | null;
    };
    purchase: {
        id: number;
        merchant_description: string;
        amount_minor: string;
        currency: Currency;
    };
    reason:
        | 'cumulative_refunds_exceed_purchase'
        | 'receipt_breakdown_allocation_requires_review';
    reason_label: string;
    linked_refund_total_minor: string;
    overage_minor: string;
};

type SuspectedDuplicateTransaction = {
    id: number;
    revision: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    category_name: string | null;
    original_purchase_id: number | null;
    has_linked_refunds: boolean;
    has_receipt_breakdown: boolean;
    protects_resolved_duplicate: boolean;
    source_reference_count: number;
    source_reference_fingerprint: string;
};

type SuspectedDuplicateReview = {
    id: number;
    revision: number;
    resolution_idempotency_key: string;
    first_transaction: SuspectedDuplicateTransaction;
    second_transaction: SuspectedDuplicateTransaction;
};

type ReviewQueueIndexProps = {
    unresolved_field_count: number;
    unresolved_refund_relationship_count: number;
    unresolved_suspected_duplicate_count: number;
    stale_transaction:
        | (Omit<ReviewTransaction, 'fields' | 'confirmed_at'> & {
              provisional_fields: ReviewableFieldName[];
          })
        | null;
    transactions: ReviewTransaction[];
    refund_relationships: RefundRelationshipReview[];
    suspected_duplicates: SuspectedDuplicateReview[];
};

function formatMinorUnits(amountMinor: string, currency: Currency): string {
    const digits = amountMinor.padStart(3, '0');
    const integerPart = digits
        .slice(0, -2)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const symbol = currency === 'USD' ? '$' : 'S/';

    return `${symbol} ${integerPart}.${digits.slice(-2)}`;
}

function survivorChoiceBlockReason(
    survivor: SuspectedDuplicateTransaction,
    transactionToVoid: SuspectedDuplicateTransaction,
): string | null {
    if (
        survivor.kind !== transactionToVoid.kind ||
        survivor.currency !== transactionToVoid.currency ||
        survivor.amount_minor !== transactionToVoid.amount_minor
    ) {
        return 'The records must have the same kind, currency, and amount.';
    }

    if (
        survivor.original_purchase_id !== transactionToVoid.original_purchase_id
    ) {
        return 'The records have different original purchase relationships.';
    }

    if (transactionToVoid.has_receipt_breakdown) {
        return 'This choice would void a Receipt Breakdown that requires separate review.';
    }

    if (transactionToVoid.has_linked_refunds) {
        return 'This choice would void a purchase that still has linked Refunds.';
    }

    if (transactionToVoid.protects_resolved_duplicate) {
        return 'This choice would void the survivor of another resolved pair.';
    }

    return null;
}

function fieldValueLabel(field: ReviewField): string {
    if (field.name === 'kind') {
        return field.value === 'refund' ? 'Refund' : 'Purchase';
    }

    if (field.name === 'amount_minor') {
        return `${field.value} minor units`;
    }

    return field.value;
}

function CorrectionControl({
    transactionId,
    field,
}: {
    transactionId: number;
    field: ReviewField;
}) {
    const id = `transaction-${transactionId}-${field.name}-correction`;

    if (field.name === 'currency') {
        return (
            <NativeSelect
                id={id}
                name="value"
                defaultValue={field.value}
                aria-label={`Correct ${field.label}`}
                options={[
                    { value: 'USD', label: 'USD' },
                    { value: 'PEN', label: 'PEN' },
                ]}
            />
        );
    }

    if (field.name === 'kind') {
        return (
            <NativeSelect
                id={id}
                name="value"
                defaultValue={field.value}
                aria-label={`Correct ${field.label}`}
                options={[
                    { value: 'purchase', label: 'Purchase' },
                    { value: 'refund', label: 'Refund' },
                ]}
            />
        );
    }

    return (
        <Input
            id={id}
            name="value"
            type={field.name === 'occurred_on' ? 'date' : 'text'}
            inputMode={field.name === 'amount_minor' ? 'numeric' : undefined}
            defaultValue={field.value}
            aria-label={`Correct ${field.label}`}
        />
    );
}

function ReviewFieldCard({
    transaction,
    field,
}: {
    transaction: ReviewTransaction;
    field: ReviewField;
}) {
    const routeArguments = {
        transaction: transaction.id,
        field: field.name,
    };

    return (
        <div className="grid gap-4 rounded-lg border p-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,24rem)] lg:items-end">
            <div className="grid gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Label className="text-sm font-medium">{field.label}</Label>
                    <Badge variant="secondary">
                        <CircleAlert />
                        Needs review
                    </Badge>
                </div>
                <p className="text-base font-semibold break-words">
                    {fieldValueLabel(field)}
                </p>
                <Form
                    {...update.form(routeArguments)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <div className="grid gap-2">
                            <input
                                type="hidden"
                                name="expected_revision"
                                value={transaction.revision}
                            />
                            <input
                                type="hidden"
                                name="resolution"
                                value="accept"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                                className="w-fit"
                            >
                                {processing ? <Spinner /> : <Check />}
                                Accept current
                            </Button>
                            <InputError message={errors.expected_revision} />
                        </div>
                    )}
                </Form>
            </div>

            <Form
                {...update.form(routeArguments)}
                options={{ preserveScroll: true }}
                className="grid gap-2"
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
                            name="resolution"
                            value="correct"
                        />
                        <Label
                            htmlFor={`transaction-${transaction.id}-${field.name}-correction`}
                        >
                            Correct {field.label}
                        </Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="min-w-0 flex-1">
                                <CorrectionControl
                                    transactionId={transaction.id}
                                    field={field}
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                {processing ? <Spinner /> : <PencilLine />}
                                Save Correction
                            </Button>
                        </div>
                        <InputError
                            message={errors.value ?? errors.expected_revision}
                        />
                    </>
                )}
            </Form>
        </div>
    );
}

function SuspectedDuplicateInspector({
    suspectedDuplicate,
}: {
    suspectedDuplicate: SuspectedDuplicateReview;
}) {
    const [survivorId, setSurvivorId] = useState<number | null>(null);
    const transactions = [
        suspectedDuplicate.first_transaction,
        suspectedDuplicate.second_transaction,
    ];
    const survivor = transactions.find(
        (transaction) => transaction.id === survivorId,
    );
    const transactionToVoid = transactions.find(
        (transaction) => transaction.id !== survivorId,
    );
    const selectedChoiceBlockReason =
        survivor && transactionToVoid
            ? survivorChoiceBlockReason(survivor, transactionToVoid)
            : null;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <ScanSearch />
                    Inspect pair
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Resolve Suspected Duplicate</DialogTitle>
                    <DialogDescription>
                        Compare both confirmed records. Nothing is merged until
                        you choose a survivor and confirm the exact effect.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...resolveSuspectedDuplicate.form(suspectedDuplicate.id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="survivor_transaction_id"
                                value={survivorId ?? ''}
                            />
                            <input
                                type="hidden"
                                name="expected_suspected_duplicate_revision"
                                value={suspectedDuplicate.revision}
                            />
                            <input
                                type="hidden"
                                name="expected_first_transaction_revision"
                                value={
                                    suspectedDuplicate.first_transaction
                                        .revision
                                }
                            />
                            <input
                                type="hidden"
                                name="expected_second_transaction_revision"
                                value={
                                    suspectedDuplicate.second_transaction
                                        .revision
                                }
                            />
                            <input
                                type="hidden"
                                name="expected_first_source_reference_fingerprint"
                                value={
                                    suspectedDuplicate.first_transaction
                                        .source_reference_fingerprint
                                }
                            />
                            <input
                                type="hidden"
                                name="expected_second_source_reference_fingerprint"
                                value={
                                    suspectedDuplicate.second_transaction
                                        .source_reference_fingerprint
                                }
                            />
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value={
                                    suspectedDuplicate.resolution_idempotency_key
                                }
                            />

                            <fieldset className="grid gap-3 md:grid-cols-2">
                                <legend className="sr-only">
                                    Choose the surviving Transaction
                                </legend>
                                {transactions.map((transaction) => {
                                    const otherTransaction = transactions.find(
                                        (candidate) =>
                                            candidate.id !== transaction.id,
                                    );
                                    const blockReason = otherTransaction
                                        ? survivorChoiceBlockReason(
                                              transaction,
                                              otherTransaction,
                                          )
                                        : null;

                                    return (
                                        <label
                                            key={transaction.id}
                                            className={`grid gap-3 rounded-lg border p-4 transition-colors ${
                                                blockReason
                                                    ? 'cursor-not-allowed opacity-60'
                                                    : 'cursor-pointer hover:bg-muted/50'
                                            } ${
                                                survivorId === transaction.id
                                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                    : ''
                                            }`}
                                        >
                                            <span className="flex items-center gap-2 font-medium">
                                                <input
                                                    type="radio"
                                                    name="survivor_choice"
                                                    value={transaction.id}
                                                    checked={
                                                        survivorId ===
                                                        transaction.id
                                                    }
                                                    disabled={
                                                        blockReason !== null
                                                    }
                                                    onChange={() =>
                                                        setSurvivorId(
                                                            transaction.id,
                                                        )
                                                    }
                                                />
                                                Keep{' '}
                                                {
                                                    transaction.merchant_description
                                                }
                                            </span>
                                            <span className="grid gap-1 text-sm text-muted-foreground">
                                                <span>
                                                    {transaction.occurred_on} ·{' '}
                                                    {transaction.kind ===
                                                    'refund'
                                                        ? 'Refund'
                                                        : 'Purchase'}
                                                </span>
                                                <span className="font-medium text-foreground tabular-nums">
                                                    {formatMinorUnits(
                                                        transaction.amount_minor,
                                                        transaction.currency,
                                                    )}{' '}
                                                    {transaction.currency}
                                                </span>
                                                <span>
                                                    {transaction.category_name ??
                                                        'Uncategorized'}
                                                </span>
                                                <span>
                                                    {
                                                        transaction.source_reference_count
                                                    }{' '}
                                                    {transaction.source_reference_count ===
                                                    1
                                                        ? 'source reference'
                                                        : 'source references'}
                                                </span>
                                                <span>
                                                    Revision{' '}
                                                    {transaction.revision}
                                                </span>
                                                {blockReason && (
                                                    <span className="font-medium text-amber-800 dark:text-amber-300">
                                                        {blockReason}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    );
                                })}
                            </fieldset>

                            {survivor &&
                            transactionToVoid &&
                            selectedChoiceBlockReason === null ? (
                                <div className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50/70 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/20">
                                    <p className="font-semibold">
                                        Exact resolution effect
                                    </p>
                                    <p>
                                        Keep {survivor.merchant_description}{' '}
                                        active.
                                    </p>
                                    <p>
                                        Move{' '}
                                        {
                                            transactionToVoid.source_reference_count
                                        }{' '}
                                        {transactionToVoid.source_reference_count ===
                                        1
                                            ? 'source reference'
                                            : 'source references'}{' '}
                                        from{' '}
                                        {transactionToVoid.merchant_description}{' '}
                                        to {survivor.merchant_description}.
                                    </p>
                                    <p>
                                        Void{' '}
                                        {transactionToVoid.merchant_description}{' '}
                                        and{' '}
                                        {transactionToVoid.kind === 'purchase'
                                            ? 'remove'
                                            : 'add back'}{' '}
                                        {formatMinorUnits(
                                            transactionToVoid.amount_minor,
                                            transactionToVoid.currency,
                                        )}{' '}
                                        {transactionToVoid.kind === 'purchase'
                                            ? 'from'
                                            : 'to'}{' '}
                                        {transactionToVoid.currency} net
                                        spending.
                                    </p>
                                    <p>
                                        {transactionToVoid.kind === 'purchase'
                                            ? 'Remove'
                                            : 'Add back'}{' '}
                                        {formatMinorUnits(
                                            transactionToVoid.amount_minor,
                                            transactionToVoid.currency,
                                        )}{' '}
                                        {transactionToVoid.kind === 'purchase'
                                            ? 'from'
                                            : 'to'}{' '}
                                        {transactionToVoid.category_name
                                            ? `${transactionToVoid.category_name} Category`
                                            : 'Uncategorized'}{' '}
                                        spending.
                                    </p>
                                    <p className="font-medium">
                                        The pair will contribute exactly once.
                                    </p>
                                </div>
                            ) : (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    Choose which Transaction to keep to preview
                                    the exact effect.
                                </p>
                            )}

                            <InputError
                                message={
                                    errors.survivor_transaction_id ??
                                    errors.expected_first_source_reference_fingerprint ??
                                    errors.expected_second_source_reference_fingerprint ??
                                    errors.suspected_duplicate_resolution
                                }
                            />
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    survivorId === null ||
                                    selectedChoiceBlockReason !== null
                                }
                            >
                                {processing && <Spinner />}
                                Confirm resolution
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function ReviewQueueIndex({
    unresolved_field_count,
    unresolved_refund_relationship_count,
    unresolved_suspected_duplicate_count,
    stale_transaction,
    transactions,
    refund_relationships,
    suspected_duplicates,
}: ReviewQueueIndexProps) {
    const unresolvedReviewCount =
        unresolved_field_count +
        unresolved_refund_relationship_count +
        unresolved_suspected_duplicate_count;

    return (
        <>
            <Head title="Review Queue" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Review Queue
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Accept uncertain details or make an authoritative
                            Correction. Transactions stay confirmed throughout.
                        </p>
                    </div>
                    <Badge variant="outline" className="w-fit">
                        <ListChecks />
                        {unresolved_refund_relationship_count === 0 &&
                        unresolved_suspected_duplicate_count === 0
                            ? `${unresolved_field_count} ${
                                  unresolved_field_count === 1
                                      ? 'field'
                                      : 'fields'
                              }`
                            : `${unresolvedReviewCount} ${
                                  unresolvedReviewCount === 1
                                      ? 'review'
                                      : 'reviews'
                              }`}
                    </Badge>
                </div>

                {stale_transaction && (
                    <Card
                        className="border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/20"
                        data-test="stale-transaction"
                    >
                        <CardHeader className="gap-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CircleAlert className="text-amber-700 dark:text-amber-400" />
                                Transaction changed during review
                            </CardTitle>
                            <CardDescription>
                                Your older update was not applied. This is the
                                current confirmed state at revision{' '}
                                {stale_transaction.revision}.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm">
                            <p className="font-medium">
                                {stale_transaction.merchant_description}
                            </p>
                            <p className="text-muted-foreground">
                                {stale_transaction.occurred_on} ·{' '}
                                {stale_transaction.currency}{' '}
                                {stale_transaction.amount_minor} minor units ·{' '}
                                {stale_transaction.kind === 'refund'
                                    ? 'Refund'
                                    : 'Purchase'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {stale_transaction.provisional_fields.length ===
                                0
                                    ? 'The newer update resolved every flagged field.'
                                    : `${stale_transaction.provisional_fields.length} flagged ${stale_transaction.provisional_fields.length === 1 ? 'field remains' : 'fields remain'} for review.`}
                            </p>
                        </CardContent>
                    </Card>
                )}

                {transactions.length === 0 &&
                refund_relationships.length === 0 &&
                suspected_duplicates.length === 0 ? (
                    <Card>
                        <CardContent className="flex min-h-64 flex-col items-center justify-center gap-3 text-center">
                            <div className="rounded-full bg-muted p-3">
                                <Check className="size-6 text-emerald-700 dark:text-emerald-400" />
                            </div>
                            <div className="grid gap-1">
                                <p className="font-medium">
                                    Review Queue is clear
                                </p>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    Every currently recorded Transaction detail
                                    has been resolved.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-5">
                        {suspected_duplicates.map((suspectedDuplicate) => (
                            <Card
                                key={suspectedDuplicate.id}
                                className="border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/20"
                            >
                                <CardHeader className="gap-3">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="grid gap-1">
                                            <CardTitle className="flex items-center gap-2">
                                                <CircleAlert className="size-5 text-amber-700 dark:text-amber-400" />
                                                Suspected Duplicate
                                            </CardTitle>
                                            <CardDescription>
                                                Similar evidence created two
                                                confirmed Transactions. Both
                                                remain included until you choose
                                                a survivor.
                                            </CardDescription>
                                        </div>
                                        <Badge
                                            variant="secondary"
                                            className="w-fit"
                                        >
                                            Relationship review
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-sm text-muted-foreground">
                                        {
                                            suspectedDuplicate.first_transaction
                                                .merchant_description
                                        }{' '}
                                        and{' '}
                                        {
                                            suspectedDuplicate
                                                .second_transaction
                                                .merchant_description
                                        }
                                    </p>
                                    <SuspectedDuplicateInspector
                                        suspectedDuplicate={suspectedDuplicate}
                                    />
                                </CardContent>
                            </Card>
                        ))}

                        {refund_relationships.map((relationship) => (
                            <Card
                                key={`${relationship.refund.id}-${relationship.reason}`}
                                className="border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/20"
                            >
                                <CardHeader className="gap-3">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="grid gap-1">
                                            <CardTitle className="flex items-center gap-2">
                                                <CircleAlert className="size-5 text-amber-700 dark:text-amber-400" />
                                                {relationship.reason_label}
                                            </CardTitle>
                                            <CardDescription>
                                                {
                                                    relationship.refund
                                                        .merchant_description
                                                }{' '}
                                                is linked to{' '}
                                                {
                                                    relationship.purchase
                                                        .merchant_description
                                                }
                                                .
                                            </CardDescription>
                                        </div>
                                        <Badge
                                            variant="secondary"
                                            className="w-fit"
                                        >
                                            Relationship review
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="grid gap-2 text-sm">
                                    {relationship.reason ===
                                    'cumulative_refunds_exceed_purchase' ? (
                                        <>
                                            <p>
                                                Linked Refunds total{' '}
                                                {
                                                    relationship.linked_refund_total_minor
                                                }{' '}
                                                {relationship.purchase.currency}{' '}
                                                minor units against a purchase
                                                of{' '}
                                                {
                                                    relationship.purchase
                                                        .amount_minor
                                                }
                                                .
                                            </p>
                                            <p className="font-medium text-amber-800 dark:text-amber-300">
                                                The confirmed Refunds remain
                                                included. The relationship is{' '}
                                                {relationship.overage_minor}{' '}
                                                minor units over the purchase.
                                            </p>
                                        </>
                                    ) : (
                                        <p>
                                            The purchase has a Receipt
                                            Breakdown. This Refund{' '}
                                            {relationship.refund.category_name
                                                ? `keeps its existing ${relationship.refund.category_name} Category`
                                                : 'remains Uncategorized'}{' '}
                                            until its allocation is reviewed
                                            independently; no Line Items were
                                            copied or inferred.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        ))}

                        {transactions.map((transaction) => (
                            <Card key={transaction.id}>
                                <CardHeader className="gap-3">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="grid gap-1">
                                            <CardTitle>
                                                {
                                                    transaction.merchant_description
                                                }
                                            </CardTitle>
                                            <CardDescription className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                <span className="inline-flex items-center gap-1">
                                                    <CalendarClock className="size-3.5" />
                                                    {transaction.occurred_on}
                                                </span>
                                                <span>
                                                    {transaction.currency}{' '}
                                                    {transaction.amount_minor}{' '}
                                                    minor units
                                                </span>
                                                <span>
                                                    {transaction.kind ===
                                                    'refund'
                                                        ? 'Refund'
                                                        : 'Purchase'}
                                                </span>
                                            </CardDescription>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Badge variant="outline">
                                                Confirmed
                                            </Badge>
                                            <Badge variant="secondary">
                                                {transaction.fields.length}{' '}
                                                {transaction.fields.length === 1
                                                    ? 'field'
                                                    : 'fields'}
                                            </Badge>
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Included in spending totals · Revision{' '}
                                        {transaction.revision}
                                    </p>
                                </CardHeader>
                                <CardContent className="grid gap-3">
                                    {transaction.fields.map((field) => (
                                        <ReviewFieldCard
                                            key={field.name}
                                            transaction={transaction}
                                            field={field}
                                        />
                                    ))}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ReviewQueueIndex.layout = {
    breadcrumbs: [
        {
            title: 'Review Queue',
            href: index(),
        },
    ],
};
