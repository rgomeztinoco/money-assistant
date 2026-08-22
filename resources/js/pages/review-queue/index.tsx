import { Deferred, Form, Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CircleAlert,
    Layers3,
    List,
    ReceiptText,
    Sparkles,
    Tags,
} from 'lucide-react';
import { update as assignLineItemCategory } from '@/actions/App/Http/Controllers/ReviewQueueLineItemCategoryController';
import { update as assignTransactionCategory } from '@/actions/App/Http/Controllers/ReviewQueueTransactionCategoryController';
import { update as resolveTransactionField } from '@/actions/App/Http/Controllers/TransactionFieldReviewController';
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
import { movementKindLabel, movementKindOptions } from '@/lib/money-movement';
import { index } from '@/routes/review_queue';
import { index as transactionsIndex } from '@/routes/transactions';
import type {
    CategoryOption,
    Currency,
    ReviewField,
    SelectedTransaction,
    TransactionKind,
} from '@/types';

type ReviewTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    confirmed_at: string;
    category: { id: number; name: string } | null;
};

type CategoryReason = {
    type: 'category';
    label: string;
};

type FieldReason = {
    type: 'field';
    label: string;
    field: ReviewField;
};

type RefundRelationshipReason = {
    type: 'refund_relationship';
    name:
        | 'cumulative_refunds_exceed_purchase'
        | 'receipt_breakdown_allocation_requires_review';
    label: string;
};

type TransactionQueueItem = {
    key: string;
    type: 'transaction';
    transaction: ReviewTransaction;
    reasons: Array<CategoryReason | FieldReason | RefundRelationshipReason>;
    merchant_context: {
        normalized_merchant: string | null;
        matching_uncategorized_count: number;
    };
};

type LineItemQueueItem = {
    key: string;
    type: 'line_item';
    transaction: ReviewTransaction;
    line_item: {
        id: number;
        line_item_id: string;
        description: string;
        quantity: string | null;
        unit_price_minor: string | null;
        line_total_minor: string;
    };
    reasons: CategoryReason[];
};

type QueueItem = TransactionQueueItem | LineItemQueueItem;

type ReviewQueue = {
    unresolved_count: number;
    item_count: number;
    current_item_key: string | null;
    current_position: number;
    view: 'guided' | 'overview';
    items: QueueItem[];
};

type ReviewQueueProps = {
    category_options: CategoryOption[];
    inspector_open: boolean;
    queue: ReviewQueue;
    selected_transaction_id?: number | null;
    selected_transaction?: SelectedTransaction | null;
};

function queueUrl(item: string | undefined, view: 'guided' | 'overview') {
    return index({
        query: {
            item,
            view: view === 'guided' ? undefined : view,
        },
    });
}

function TransactionSummary({
    transaction,
}: {
    transaction: ReviewTransaction;
}) {
    return (
        <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <p className="text-sm text-muted-foreground">
                    Merchant or description
                </p>
                <p className="font-semibold break-words">
                    {transaction.merchant_description}
                </p>
            </div>
            <div>
                <p className="text-sm text-muted-foreground">Amount</p>
                <p className="font-medium tabular-nums">
                    {formatMinorUnits(
                        transaction.amount_minor,
                        transaction.currency,
                    )}
                </p>
            </div>
            <div>
                <p className="text-sm text-muted-foreground">Date</p>
                <p className="font-medium">{transaction.occurred_on}</p>
            </div>
            <div>
                <p className="text-sm text-muted-foreground">Kind</p>
                <p className="font-medium">
                    {movementKindLabel(transaction.kind)}
                </p>
            </div>
            <div>
                <p className="text-sm text-muted-foreground">Category</p>
                <p className="font-medium">
                    {transaction.category?.name ?? 'Uncategorized'}
                </p>
            </div>
        </div>
    );
}

function NextReviewItemInput({
    nextItem,
}: {
    nextItem: QueueItem | undefined;
}) {
    if (!nextItem) {
        return null;
    }

    return <input type="hidden" name="next_review_item" value={nextItem.key} />;
}

function TransactionCategoryDecision({
    item,
    categoryOptions,
    nextItem,
}: {
    item: TransactionQueueItem;
    categoryOptions: CategoryOption[];
    nextItem: QueueItem | undefined;
}) {
    const normalizedMerchant = item.merchant_context.normalized_merchant;
    const matchingCount = item.merchant_context.matching_uncategorized_count;

    return (
        <Form
            {...assignTransactionCategory.form(item.transaction.id)}
            options={{ preserveScroll: true }}
            className="grid gap-5 rounded-lg border p-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`category-${item.transaction.id}`}>
                            Assign a Category
                        </Label>
                        <NativeSelect
                            id={`category-${item.transaction.id}`}
                            name="category_id"
                            defaultValue=""
                            required
                            options={[
                                { value: '', label: 'Choose a Category' },
                                ...categoryOptions.map((category) => ({
                                    value: category.id.toString(),
                                    label: category.path,
                                })),
                            ]}
                        />
                        <InputError message={errors.category_id} />
                    </div>

                    <div className="grid gap-4 rounded-lg bg-muted/40 p-4">
                        <div className="grid gap-1">
                            <h3 className="flex items-center gap-2 font-semibold">
                                <Sparkles className="size-4" /> Optional future
                                behavior
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                A Merchant Rule is separate from this owner
                                assignment. It applies only to future
                                Uncategorized Transactions that match the
                                normalized merchant and selected scope.
                            </p>
                        </div>
                        {normalizedMerchant ? (
                            <>
                                <p className="rounded-md border bg-background p-3 text-sm">
                                    Normalized merchant:{' '}
                                    <span className="font-medium break-words">
                                        {normalizedMerchant}
                                    </span>
                                </p>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="create-merchant-rule">
                                            Create future rule?
                                        </Label>
                                        <NativeSelect
                                            id="create-merchant-rule"
                                            name="create_merchant_rule"
                                            defaultValue="0"
                                            options={[
                                                { value: '0', label: 'No' },
                                                { value: '1', label: 'Yes' },
                                            ]}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="rule-transaction-kind">
                                            Rule kind
                                        </Label>
                                        <NativeSelect
                                            id="rule-transaction-kind"
                                            name="rule_transaction_kind"
                                            defaultValue={item.transaction.kind}
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Any kind',
                                                },
                                                {
                                                    value: 'spending',
                                                    label: 'Spending',
                                                },
                                                {
                                                    value: 'refund',
                                                    label: 'Refunds',
                                                },
                                            ]}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="rule-currency">
                                            Rule currency
                                        </Label>
                                        <NativeSelect
                                            id="rule-currency"
                                            name="rule_currency"
                                            defaultValue={
                                                item.transaction.currency
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Any currency',
                                                },
                                                { value: 'PEN', label: 'PEN' },
                                                { value: 'USD', label: 'USD' },
                                            ]}
                                        />
                                    </div>
                                </div>
                                <InputError
                                    message={
                                        errors.create_merchant_rule ??
                                        errors.merchant_context
                                    }
                                />
                            </>
                        ) : (
                            <>
                                <input
                                    type="hidden"
                                    name="create_merchant_rule"
                                    value="0"
                                />
                                <p className="text-sm text-muted-foreground">
                                    This description cannot form a normalized
                                    merchant key, so no Merchant Rule is
                                    offered.
                                </p>
                            </>
                        )}
                    </div>

                    <div className="grid gap-3 rounded-lg bg-muted/40 p-4">
                        <div className="grid gap-1">
                            <h3 className="flex items-center gap-2 font-semibold">
                                <Layers3 className="size-4" /> Optional current
                                cleanup
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {matchingCount}{' '}
                                {matchingCount === 1
                                    ? 'current Uncategorized Transaction matches'
                                    : 'current Uncategorized Transactions match'}{' '}
                                this normalized merchant, including this one.
                                Existing Category assignments will not change.
                            </p>
                        </div>
                        <div className="grid gap-2 sm:max-w-sm">
                            <Label htmlFor="bulk-assign">
                                Apply this Category to all {matchingCount}{' '}
                                matches?
                            </Label>
                            <NativeSelect
                                id="bulk-assign"
                                name="bulk_assign"
                                defaultValue="0"
                                disabled={normalizedMerchant === null}
                                options={[
                                    { value: '0', label: 'No, only this one' },
                                    {
                                        value: '1',
                                        label: `Yes, confirm ${matchingCount} assignments`,
                                    },
                                ]}
                            />
                            {normalizedMerchant === null && (
                                <input
                                    type="hidden"
                                    name="bulk_assign"
                                    value="0"
                                />
                            )}
                        </div>
                        <InputError message={errors.bulk_assign} />
                    </div>

                    {item.reasons.length === 1 && (
                        <NextReviewItemInput nextItem={nextItem} />
                    )}
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Assign Category and continue
                    </Button>
                </>
            )}
        </Form>
    );
}

function FieldCorrectionInput({
    field,
    transactionId,
}: {
    field: ReviewField;
    transactionId: number;
}) {
    const id = `field-${transactionId}-${field.name}`;

    switch (field.name) {
        case 'occurred_on':
            return (
                <Input
                    id={id}
                    name="value"
                    type="date"
                    defaultValue={field.value}
                    required
                />
            );
        case 'amount_minor':
            return (
                <Input
                    id={id}
                    name="value"
                    type="number"
                    min="1"
                    step="1"
                    inputMode="numeric"
                    defaultValue={field.value}
                    required
                />
            );
        case 'currency':
            return (
                <NativeSelect
                    id={id}
                    name="value"
                    defaultValue={field.value}
                    options={[
                        { value: 'PEN', label: 'PEN' },
                        { value: 'USD', label: 'USD' },
                    ]}
                />
            );
        case 'kind':
            return (
                <NativeSelect
                    id={id}
                    name="value"
                    defaultValue={field.value}
                    options={movementKindOptions.filter(
                        ({ value }) =>
                            value === 'spending' || value === 'refund',
                    )}
                />
            );
        case 'merchant_description':
            return (
                <Input
                    id={id}
                    name="value"
                    defaultValue={field.value}
                    maxLength={255}
                    required
                />
            );
        default: {
            const exhaustiveField: never = field.name;

            return exhaustiveField;
        }
    }
}

function FieldDecision({
    item,
    reason,
    nextItem,
}: {
    item: TransactionQueueItem;
    reason: FieldReason;
    nextItem: QueueItem | undefined;
}) {
    const advancesAfterResolution = item.reasons.length === 1;
    const routeParameters = {
        transaction: item.transaction.id,
        field: reason.field.name,
    };

    return (
        <div className="grid gap-4 rounded-lg border p-4">
            <div className="grid gap-1">
                <h3 className="font-semibold">{reason.field.label}</h3>
                <p className="text-sm text-muted-foreground">
                    Confirm the current value or correct it. Either decision
                    clears this field&apos;s review flag.
                </p>
                <p className="rounded-md bg-muted p-3 text-sm break-words">
                    Current value:{' '}
                    <span className="font-medium">{reason.field.value}</span>
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Form
                    {...resolveTransactionField.form(routeParameters)}
                    options={{ preserveScroll: true }}
                    className="grid content-start gap-2 rounded-md bg-muted/40 p-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="resolution"
                                value="accept"
                            />
                            {advancesAfterResolution && (
                                <NextReviewItemInput nextItem={nextItem} />
                            )}
                            <p className="text-sm">
                                Keep the current value as confirmed.
                            </p>
                            <InputError message={errors.resolution} />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Accept current value
                            </Button>
                        </>
                    )}
                </Form>
                <Form
                    {...resolveTransactionField.form(routeParameters)}
                    options={{ preserveScroll: true }}
                    className="grid content-start gap-2 rounded-md bg-muted/40 p-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="resolution"
                                value="correct"
                            />
                            {advancesAfterResolution && (
                                <NextReviewItemInput nextItem={nextItem} />
                            )}
                            <Label
                                htmlFor={`field-${item.transaction.id}-${reason.field.name}`}
                            >
                                Correct {reason.field.label.toLowerCase()}
                            </Label>
                            <FieldCorrectionInput
                                field={reason.field}
                                transactionId={item.transaction.id}
                            />
                            <InputError message={errors.value} />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Save {reason.field.label.toLowerCase()}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}

function TransactionDecision({
    item,
    categoryOptions,
    nextItem,
}: {
    item: TransactionQueueItem;
    categoryOptions: CategoryOption[];
    nextItem: QueueItem | undefined;
}) {
    return (
        <div className="grid gap-5">
            <TransactionSummary transaction={item.transaction} />
            <div className="grid gap-2">
                <h2 className="font-semibold">Why this needs attention</h2>
                {item.reasons.map((reason) => (
                    <div
                        key={
                            reason.type === 'field'
                                ? `${reason.type}:${reason.field.name}`
                                : reason.type === 'refund_relationship'
                                  ? `${reason.type}:${reason.name}`
                                  : reason.type
                        }
                        className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/20 dark:text-amber-100"
                    >
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>{reason.label}</span>
                    </div>
                ))}
            </div>

            {item.reasons.map((reason) => {
                switch (reason.type) {
                    case 'category':
                        return (
                            <TransactionCategoryDecision
                                key="category"
                                item={item}
                                categoryOptions={categoryOptions}
                                nextItem={nextItem}
                            />
                        );
                    case 'field':
                        return (
                            <FieldDecision
                                key={reason.field.name}
                                item={item}
                                reason={reason}
                                nextItem={nextItem}
                            />
                        );
                    case 'refund_relationship':
                        return (
                            <div
                                key={reason.name}
                                className="grid gap-3 rounded-lg border p-4"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {reason.name ===
                                    'cumulative_refunds_exceed_purchase'
                                        ? 'Review the linked purchase and correct the Refund relationship before continuing.'
                                        : 'This Refund needs an explicit allocation because its purchase has a Receipt Breakdown.'}
                                </p>
                                <Button asChild variant="outline">
                                    <Link
                                        href={index({
                                            query: {
                                                item: item.key,
                                                selected: item.transaction.id,
                                            },
                                        })}
                                    >
                                        Correct this relationship
                                    </Link>
                                </Button>
                            </div>
                        );
                    default: {
                        const exhaustiveReason: never = reason;

                        return exhaustiveReason;
                    }
                }
            })}
        </div>
    );
}

function LineItemDecision({
    item,
    categoryOptions,
    nextItem,
}: {
    item: LineItemQueueItem;
    categoryOptions: CategoryOption[];
    nextItem: QueueItem | undefined;
}) {
    return (
        <div className="grid gap-5">
            <TransactionSummary transaction={item.transaction} />
            <div className="grid gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950/20 dark:text-amber-100">
                <div className="flex items-start gap-2">
                    <ReceiptText className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <h2 className="font-semibold">
                            Uncategorized Line Item
                        </h2>
                        <p className="text-sm">
                            Assign the Category for this exact part of the
                            Receipt Breakdown. The reconciled Transaction amount
                            does not change.
                        </p>
                    </div>
                </div>
                <dl className="grid gap-3 rounded-md border border-amber-300 bg-background/80 p-3 text-sm text-foreground sm:grid-cols-2 dark:border-amber-800">
                    <div>
                        <dt className="text-muted-foreground">Description</dt>
                        <dd className="font-medium break-words">
                            {item.line_item.description}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Line total</dt>
                        <dd className="font-medium tabular-nums">
                            {formatMinorUnits(
                                item.line_item.line_total_minor,
                                item.transaction.currency,
                            )}
                        </dd>
                    </div>
                </dl>
            </div>
            <Form
                {...assignLineItemCategory.form(item.line_item.id)}
                options={{ preserveScroll: true }}
                className="grid gap-3 rounded-lg border p-4"
            >
                {({ errors, processing }) => (
                    <>
                        <Label htmlFor={`line-item-${item.line_item.id}`}>
                            Line Item Category
                        </Label>
                        <NativeSelect
                            id={`line-item-${item.line_item.id}`}
                            name="category_id"
                            defaultValue=""
                            required
                            options={[
                                { value: '', label: 'Choose a Category' },
                                ...categoryOptions.map((category) => ({
                                    value: category.id.toString(),
                                    label: category.path,
                                })),
                            ]}
                        />
                        <InputError message={errors.category_id} />
                        <NextReviewItemInput nextItem={nextItem} />
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Assign Line Item Category and continue
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function QueueOverview({ queue }: { queue: ReviewQueue }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Review Queue overview</CardTitle>
                <CardDescription>
                    Inspect every unresolved Transaction and Line Item. Your
                    guided position stays selected.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
                {queue.items.map((item, itemIndex) => (
                    <div
                        key={item.key}
                        className={`grid gap-3 rounded-lg border p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center ${item.key === queue.current_item_key ? 'border-primary bg-primary/5' : ''}`}
                    >
                        <div className="grid min-w-0 gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">
                                    {item.type === 'transaction'
                                        ? 'Transaction'
                                        : 'Line Item'}
                                </Badge>
                                <span className="text-sm text-muted-foreground">
                                    {itemIndex + 1} of {queue.item_count}
                                </span>
                            </div>
                            <p className="font-semibold break-words">
                                {item.type === 'transaction'
                                    ? item.transaction.merchant_description
                                    : item.line_item.description}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {item.reasons
                                    .map((reason) => reason.label)
                                    .join(' ')}
                            </p>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={queueUrl(item.key, 'guided')}>
                                Review this item
                            </Link>
                        </Button>
                    </div>
                ))}
                <Button asChild className="sm:w-fit">
                    <Link
                        href={queueUrl(
                            queue.current_item_key ?? undefined,
                            'guided',
                        )}
                    >
                        Continue guided review
                    </Link>
                </Button>
            </CardContent>
        </Card>
    );
}

export default function ReviewQueueIndex({
    category_options: categoryOptions,
    inspector_open: inspectorOpen,
    queue,
    selected_transaction_id: selectedTransactionId,
    selected_transaction: selectedTransaction,
}: ReviewQueueProps) {
    const currentIndex = queue.items.findIndex(
        (item) => item.key === queue.current_item_key,
    );
    const currentItem =
        currentIndex >= 0 ? queue.items[currentIndex] : undefined;
    const previousItem =
        currentIndex > 0 ? queue.items[currentIndex - 1] : undefined;
    const nextItem =
        currentIndex >= 0 && queue.items.length > 1
            ? queue.items[(currentIndex + 1) % queue.items.length]
            : undefined;
    const advancesAfterInspectorSave =
        currentItem?.type === 'transaction' && currentItem.reasons.length === 1;

    function handleInspectorOpenChange(open: boolean) {
        if (open) {
            return;
        }

        router.get(
            queueUrl(currentItem?.key, queue.view),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Review Queue" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Review Queue
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Resolve one current Transaction or Line Item at a
                            time. Confirmed spending remains included while it
                            needs review.
                        </p>
                    </div>
                    <Badge variant="outline" className="w-fit">
                        <CircleAlert /> {queue.unresolved_count} unresolved
                    </Badge>
                </div>

                {queue.item_count === 0 || !currentItem ? (
                    <Card>
                        <CardContent className="flex min-h-64 flex-col items-center justify-center gap-3 p-6 text-center">
                            <Tags className="size-9 text-muted-foreground" />
                            <div className="grid gap-1">
                                <h2 className="font-semibold">
                                    Review Queue is clear
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    No current Transaction or Line Item needs an
                                    owner decision.
                                </p>
                            </div>
                            <Button asChild variant="outline">
                                <Link href={transactionsIndex()}>
                                    View Transactions
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : queue.view === 'overview' ? (
                    <QueueOverview queue={queue} />
                ) : (
                    <Card className="min-w-0">
                        <CardHeader className="gap-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <CardTitle>
                                        {currentItem.type === 'transaction'
                                            ? 'Review Transaction'
                                            : 'Review Line Item'}
                                    </CardTitle>
                                    <CardDescription>
                                        Item {queue.current_position} of{' '}
                                        {queue.item_count}.{' '}
                                        {currentItem.reasons.length}{' '}
                                        {currentItem.reasons.length === 1
                                            ? 'reason'
                                            : 'reasons'}{' '}
                                        on this item.
                                    </CardDescription>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={queueUrl(
                                            currentItem.key,
                                            'overview',
                                        )}
                                    >
                                        <List /> Overview
                                    </Link>
                                </Button>
                            </div>
                            <progress
                                className="h-2 w-full overflow-hidden rounded-full accent-primary"
                                value={queue.current_position}
                                max={queue.item_count}
                            >
                                {queue.current_position} of {queue.item_count}
                            </progress>
                        </CardHeader>
                        <CardContent className="grid gap-6">
                            {currentItem.type === 'transaction' ? (
                                <TransactionDecision
                                    item={currentItem}
                                    categoryOptions={categoryOptions}
                                    nextItem={nextItem}
                                />
                            ) : (
                                <LineItemDecision
                                    item={currentItem}
                                    categoryOptions={categoryOptions}
                                    nextItem={nextItem}
                                />
                            )}

                            <div className="grid grid-cols-2 gap-3 border-t pt-5 sm:flex sm:items-center sm:justify-between">
                                <Button
                                    asChild={previousItem !== undefined}
                                    variant="outline"
                                    disabled={previousItem === undefined}
                                >
                                    {previousItem ? (
                                        <Link
                                            href={queueUrl(
                                                previousItem.key,
                                                'guided',
                                            )}
                                        >
                                            <ArrowLeft /> Back
                                        </Link>
                                    ) : (
                                        <span>
                                            <ArrowLeft /> Back
                                        </span>
                                    )}
                                </Button>
                                <Button
                                    asChild={nextItem !== undefined}
                                    variant="secondary"
                                    disabled={nextItem === undefined}
                                >
                                    {nextItem ? (
                                        <Link
                                            href={queueUrl(
                                                nextItem.key,
                                                'guided',
                                            )}
                                        >
                                            Skip <ArrowRight />
                                        </Link>
                                    ) : (
                                        <span>
                                            Skip <ArrowRight />
                                        </span>
                                    )}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            {inspectorOpen &&
                selectedTransactionId !== undefined &&
                selectedTransactionId !== null && (
                    <Deferred
                        data="selected_transaction"
                        fallback={
                            <div className="fixed inset-y-0 right-0 z-50 grid w-full max-w-2xl place-items-center border-l bg-background/95">
                                <Spinner className="size-6" />
                            </div>
                        }
                    >
                        <TransactionInspector
                            transaction={selectedTransaction ?? null}
                            categoryOptions={categoryOptions}
                            onOpenChange={handleInspectorOpenChange}
                            nextReviewItem={
                                advancesAfterInspectorSave
                                    ? nextItem?.key
                                    : undefined
                            }
                        />
                    </Deferred>
                )}
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
