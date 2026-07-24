import { Form, Head } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    CircleAlert,
    ListChecks,
    PencilLine,
} from 'lucide-react';
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

type ReviewQueueIndexProps = {
    unresolved_field_count: number;
    unresolved_refund_relationship_count: number;
    stale_transaction:
        | (Omit<ReviewTransaction, 'fields' | 'confirmed_at'> & {
              provisional_fields: ReviewableFieldName[];
          })
        | null;
    transactions: ReviewTransaction[];
    refund_relationships: RefundRelationshipReview[];
};

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

export default function ReviewQueueIndex({
    unresolved_field_count,
    unresolved_refund_relationship_count,
    stale_transaction,
    transactions,
    refund_relationships,
}: ReviewQueueIndexProps) {
    const unresolvedReviewCount =
        unresolved_field_count + unresolved_refund_relationship_count;

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
                        {unresolved_refund_relationship_count === 0
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
                refund_relationships.length === 0 ? (
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
