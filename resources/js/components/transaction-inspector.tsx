import { Form } from '@inertiajs/react';
import {
    Check,
    CircleAlert,
    CircleOff,
    History,
    Link2,
    PencilLine,
    Plus,
    ReceiptText,
    ScanSearch,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import {
    destroy as removeReceiptBreakdown,
    update as saveReceiptBreakdown,
} from '@/actions/App/Http/Controllers/ReceiptBreakdownController';
import { store as resolveSuspectedDuplicate } from '@/actions/App/Http/Controllers/SuspectedDuplicateResolutionController';
import { update as updateCategory } from '@/actions/App/Http/Controllers/TransactionCategoryController';
import { update } from '@/actions/App/Http/Controllers/TransactionFieldReviewController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import type {
    DuplicateRelationship,
    DuplicateTransaction,
    ReviewField,
    SelectedTransaction,
    CategoryOption,
} from '@/types';

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

function ReviewFieldControl({
    transaction,
    field,
}: {
    transaction: SelectedTransaction;
    field: ReviewField;
}) {
    const routeArguments = {
        transaction: transaction.id,
        field: field.name,
    };

    return (
        <div className="grid gap-3 rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-sm font-medium">{field.label}</p>
                    <p className="text-sm text-muted-foreground">
                        Current: {field.value}
                    </p>
                </div>
                <Form
                    {...update.form(routeArguments)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <div className="grid gap-1">
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

function survivorChoiceBlockReason(
    survivor: DuplicateTransaction,
    transactionToVoid: DuplicateTransaction,
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

    if (
        survivor.has_receipt_breakdown &&
        transactionToVoid.has_receipt_breakdown
    ) {
        return 'Both Transactions have a Receipt Breakdown.';
    }

    if (transactionToVoid.has_linked_refunds) {
        return 'This choice would void a purchase that still has linked Refunds.';
    }

    if (transactionToVoid.protects_resolved_duplicate) {
        return 'This choice would void the survivor of another resolved pair.';
    }

    return null;
}

function SuspectedDuplicateDialog({
    relationship,
}: {
    relationship: DuplicateRelationship;
}) {
    const [survivorId, setSurvivorId] = useState<number | null>(null);
    const transactions = [
        relationship.first_transaction,
        relationship.second_transaction,
    ];
    const survivor = transactions.find(
        (transaction) => transaction.id === survivorId,
    );
    const transactionToVoid = transactions.find(
        (transaction) => transaction.id !== survivorId,
    );
    const blockReason =
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
                        Compare both confirmed records and preview the exact
                        effect before choosing a survivor.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...resolveSuspectedDuplicate.form(relationship.id)}
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
                                value={relationship.revision}
                            />
                            <input
                                type="hidden"
                                name="expected_first_transaction_revision"
                                value={relationship.first_transaction.revision}
                            />
                            <input
                                type="hidden"
                                name="expected_second_transaction_revision"
                                value={relationship.second_transaction.revision}
                            />
                            <input
                                type="hidden"
                                name="expected_first_source_reference_fingerprint"
                                value={
                                    relationship.first_transaction
                                        .source_reference_fingerprint
                                }
                            />
                            <input
                                type="hidden"
                                name="expected_second_source_reference_fingerprint"
                                value={
                                    relationship.second_transaction
                                        .source_reference_fingerprint
                                }
                            />
                            <input
                                type="hidden"
                                name="expected_first_receipt_breakdown_fingerprint"
                                value={
                                    relationship.first_transaction
                                        .receipt_breakdown_fingerprint
                                }
                            />
                            <input
                                type="hidden"
                                name="expected_second_receipt_breakdown_fingerprint"
                                value={
                                    relationship.second_transaction
                                        .receipt_breakdown_fingerprint
                                }
                            />
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value={relationship.resolution_idempotency_key}
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
                                    const choiceBlockReason = otherTransaction
                                        ? survivorChoiceBlockReason(
                                              transaction,
                                              otherTransaction,
                                          )
                                        : null;

                                    return (
                                        <label
                                            key={transaction.id}
                                            className={`grid gap-2 rounded-lg border p-4 ${choiceBlockReason ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-muted/50'} ${survivorId === transaction.id ? 'border-primary bg-primary/5 ring-1 ring-primary' : ''}`}
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
                                                        choiceBlockReason !==
                                                        null
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
                                            <span className="text-sm text-muted-foreground">
                                                {transaction.occurred_on} ·{' '}
                                                {formatMinorUnits(
                                                    transaction.amount_minor,
                                                    transaction.currency,
                                                )}{' '}
                                                ·{' '}
                                                {transaction.category_name ??
                                                    'Uncategorized'}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {
                                                    transaction.source_reference_count
                                                }{' '}
                                                {transaction.source_reference_count ===
                                                1
                                                    ? 'source reference'
                                                    : 'source references'}
                                            </span>
                                            {choiceBlockReason && (
                                                <span className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                                    {choiceBlockReason}
                                                </span>
                                            )}
                                        </label>
                                    );
                                })}
                            </fieldset>
                            {survivor && transactionToVoid && !blockReason ? (
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
                                    {transactionToVoid.has_receipt_breakdown && (
                                        <p>
                                            Move the Receipt Breakdown with all
                                            Line Items intact from{' '}
                                            {
                                                transactionToVoid.merchant_description
                                            }{' '}
                                            to {survivor.merchant_description}.
                                        </p>
                                    )}
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
                                    errors.suspected_duplicate_resolution
                                }
                            />
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    survivorId === null ||
                                    blockReason !== null
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

function ReceiptBreakdownSection({
    transaction,
    categoryOptions,
}: {
    transaction: SelectedTransaction;
    categoryOptions: CategoryOption[];
}) {
    const breakdown = transaction.receipt_breakdown;
    const [lineItems, setLineItems] = useState(
        () =>
            breakdown?.line_items.map((lineItem) => ({
                ...lineItem,
                clientId: lineItem.id,
            })) ?? [
                {
                    id: '',
                    clientId: crypto.randomUUID(),
                    description: transaction.merchant_description,
                    quantity: null,
                    unit_price_minor: null,
                    line_total_minor: transaction.amount_minor,
                    category:
                        transaction.category === null
                            ? null
                            : {
                                  id: transaction.category.id,
                                  name: transaction.category.name,
                              },
                },
            ],
    );

    function updateLineItem(
        clientId: string,
        field:
            | 'description'
            | 'quantity'
            | 'unit_price_minor'
            | 'line_total_minor'
            | 'category_id',
        value: string,
    ) {
        setLineItems((currentLineItems) =>
            currentLineItems.map((lineItem) => {
                if (lineItem.clientId !== clientId) {
                    return lineItem;
                }

                if (field === 'category_id') {
                    const category = categoryOptions.find(
                        (option) => option.id.toString() === value,
                    );

                    return {
                        ...lineItem,
                        category: category
                            ? { id: category.id, name: category.path }
                            : null,
                    };
                }

                return { ...lineItem, [field]: value };
            }),
        );
    }

    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 font-semibold">
                    <ReceiptText className="size-4" /> Receipt Breakdown
                </h2>
                {breakdown && <Badge variant="outline">Active</Badge>}
            </div>

            <div className="grid gap-3 rounded-lg border p-4">
                <div className="grid gap-1 text-sm">
                    <p className="font-medium">
                        {breakdown
                            ? 'Current itemization'
                            : 'Manual itemization'}
                    </p>
                    <p className="text-muted-foreground">
                        Signed Line Item totals must equal{' '}
                        {formatMinorUnits(
                            transaction.amount_minor,
                            transaction.currency,
                        )}
                        . Quantity and unit price are optional context only.
                    </p>
                </div>

                <Form
                    {...saveReceiptBreakdown.form(transaction.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            {lineItems.map((lineItem, index) => (
                                <div
                                    key={lineItem.clientId}
                                    className="grid gap-3 rounded-md border bg-background p-3 sm:grid-cols-2"
                                >
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label
                                            htmlFor={`receipt-line-${lineItem.clientId}-description`}
                                        >
                                            Description
                                        </Label>
                                        <Input
                                            id={`receipt-line-${lineItem.clientId}-description`}
                                            name={`line_items[${index}][description]`}
                                            value={lineItem.description}
                                            onChange={(event) =>
                                                updateLineItem(
                                                    lineItem.clientId,
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`receipt-line-${lineItem.clientId}-quantity`}
                                        >
                                            Quantity
                                        </Label>
                                        <Input
                                            id={`receipt-line-${lineItem.clientId}-quantity`}
                                            name={`line_items[${index}][quantity]`}
                                            inputMode="decimal"
                                            value={lineItem.quantity ?? ''}
                                            onChange={(event) =>
                                                updateLineItem(
                                                    lineItem.clientId,
                                                    'quantity',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Optional context"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`receipt-line-${lineItem.clientId}-unit-price`}
                                        >
                                            Unit price in minor units
                                        </Label>
                                        <Input
                                            id={`receipt-line-${lineItem.clientId}-unit-price`}
                                            name={`line_items[${index}][unit_price_minor]`}
                                            type="number"
                                            step="1"
                                            value={
                                                lineItem.unit_price_minor ?? ''
                                            }
                                            onChange={(event) =>
                                                updateLineItem(
                                                    lineItem.clientId,
                                                    'unit_price_minor',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Optional context"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`receipt-line-${lineItem.clientId}-total`}
                                        >
                                            Signed total in minor units
                                        </Label>
                                        <Input
                                            id={`receipt-line-${lineItem.clientId}-total`}
                                            name={`line_items[${index}][line_total_minor]`}
                                            type="number"
                                            step="1"
                                            value={lineItem.line_total_minor}
                                            onChange={(event) =>
                                                updateLineItem(
                                                    lineItem.clientId,
                                                    'line_total_minor',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`receipt-line-${lineItem.clientId}-category`}
                                        >
                                            Category
                                        </Label>
                                        <NativeSelect
                                            id={`receipt-line-${lineItem.clientId}-category`}
                                            name={`line_items[${index}][category_id]`}
                                            value={
                                                lineItem.category?.id.toString() ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                updateLineItem(
                                                    lineItem.clientId,
                                                    'category_id',
                                                    event.target.value,
                                                )
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Uncategorized',
                                                },
                                                ...categoryOptions.map(
                                                    (category) => ({
                                                        value: category.id.toString(),
                                                        label: category.path,
                                                    }),
                                                ),
                                            ]}
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="w-fit text-destructive sm:col-span-2"
                                        disabled={lineItems.length === 1}
                                        onClick={() =>
                                            setLineItems((currentLineItems) =>
                                                currentLineItems.filter(
                                                    (candidate) =>
                                                        candidate.clientId !==
                                                        lineItem.clientId,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 /> Remove item
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                disabled={lineItems.length >= 200}
                                onClick={() =>
                                    setLineItems((currentLineItems) => [
                                        ...currentLineItems,
                                        {
                                            id: '',
                                            clientId: crypto.randomUUID(),
                                            description: '',
                                            quantity: null,
                                            unit_price_minor: null,
                                            line_total_minor: '1',
                                            category: null,
                                        },
                                    ])
                                }
                            >
                                <Plus /> Add Line Item
                            </Button>
                            <InputError message={errors.line_items} />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {breakdown
                                    ? 'Replace Receipt Breakdown'
                                    : 'Save Receipt Breakdown'}
                            </Button>
                        </>
                    )}
                </Form>

                {breakdown && (
                    <Form
                        {...removeReceiptBreakdown.form(transaction.id)}
                        options={{ preserveScroll: true, preserveState: true }}
                        className="grid gap-1"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    className="w-fit text-destructive"
                                    disabled={processing}
                                >
                                    {processing ? <Spinner /> : <Trash2 />}
                                    Remove Receipt Breakdown
                                </Button>
                                <InputError
                                    message={errors.receipt_breakdown}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Reporting will return to the Transaction
                                    Category.
                                </p>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </section>
    );
}

export function TransactionInspector({
    transaction,
    categoryOptions,
    onOpenChange,
}: {
    transaction: SelectedTransaction | null;
    categoryOptions: CategoryOption[];
    onOpenChange: (open: boolean) => void;
}) {
    const unresolvedReviewCount = transaction
        ? transaction.review.fields.length +
          transaction.review.refund_relationship_reasons.length +
          transaction.duplicate_relationships.filter(
              (relationship) => relationship.status === 'suspected',
          ).length +
          (transaction.receipt_breakdown?.line_items.filter(
              (lineItem) => lineItem.category === null,
          ).length ?? 0)
        : 0;

    return (
        <Sheet open={transaction !== null} onOpenChange={onOpenChange}>
            {transaction && (
                <SheetContent className="w-full overflow-y-auto sm:max-w-xl lg:max-w-2xl">
                    <SheetHeader className="border-b">
                        <div className="flex flex-wrap items-center gap-2 pr-8">
                            <SheetTitle>
                                {transaction.merchant_description}
                            </SheetTitle>
                            <Badge variant="outline">Confirmed</Badge>
                            {transaction.voided_at && (
                                <Badge variant="secondary">
                                    <CircleOff /> Voided
                                </Badge>
                            )}
                            {unresolvedReviewCount > 0 && (
                                <Badge variant="secondary">
                                    <CircleAlert />
                                    {unresolvedReviewCount}{' '}
                                    {unresolvedReviewCount === 1
                                        ? 'review'
                                        : 'reviews'}
                                </Badge>
                            )}
                            {unresolvedReviewCount === 0 && (
                                <Badge variant="outline">
                                    <Check /> Review clear
                                </Badge>
                            )}
                        </div>
                        <SheetDescription>
                            Transaction #{transaction.id} · Revision{' '}
                            {transaction.revision}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="grid gap-6 px-4 pb-6">
                        <section className="grid gap-3">
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Current values
                            </h2>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg border p-4 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Occurrence date
                                    </dt>
                                    <dd className="font-medium">
                                        {transaction.occurred_on}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Amount
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {formatMinorUnits(
                                            transaction.amount_minor,
                                            transaction.currency,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Kind
                                    </dt>
                                    <dd className="font-medium">
                                        {transaction.kind === 'refund'
                                            ? 'Refund'
                                            : 'Purchase'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Category
                                    </dt>
                                    <dd className="font-medium">
                                        {transaction.category?.name ??
                                            'Uncategorized'}
                                    </dd>
                                </div>
                            </dl>
                            <p className="text-xs text-muted-foreground">
                                {transaction.voided_at
                                    ? 'Excluded from spending totals while Voided.'
                                    : 'Included in spending totals.'}{' '}
                                Confirmed{' '}
                                {transaction.confirmed_at.slice(0, 10)}.
                            </p>
                            <Form
                                {...updateCategory.form(transaction.id)}
                                options={{
                                    preserveScroll: true,
                                    preserveState: true,
                                }}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="expected_revision"
                                            value={transaction.revision}
                                        />
                                        <Label
                                            htmlFor={`transaction-${transaction.id}-category`}
                                        >
                                            Assign Category
                                        </Label>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <NativeSelect
                                                id={`transaction-${transaction.id}-category`}
                                                name="category_id"
                                                defaultValue={
                                                    transaction.category?.id.toString() ??
                                                    ''
                                                }
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'Uncategorized',
                                                    },
                                                    ...categoryOptions.map(
                                                        (category) => ({
                                                            value: category.id.toString(),
                                                            label: category.path,
                                                        }),
                                                    ),
                                                ]}
                                            />
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                Save Category
                                            </Button>
                                        </div>
                                        <InputError
                                            message={
                                                errors.category_id ??
                                                errors.expected_revision
                                            }
                                        />
                                    </>
                                )}
                            </Form>
                        </section>

                        <ReceiptBreakdownSection
                            key={`${transaction.receipt_breakdown?.id ?? 'none'}-${transaction.receipt_breakdown?.line_items.map((lineItem) => lineItem.id).join('-') ?? ''}`}
                            transaction={transaction}
                            categoryOptions={categoryOptions}
                        />

                        {transaction.review.fields.length > 0 && (
                            <section className="grid gap-3">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="font-semibold">
                                        Uncertain details
                                    </h2>
                                    <Badge variant="secondary">
                                        {transaction.review.fields.length}{' '}
                                        {transaction.review.fields.length === 1
                                            ? 'field'
                                            : 'fields'}
                                    </Badge>
                                </div>
                                {transaction.review.fields.map((field) => (
                                    <ReviewFieldControl
                                        key={field.name}
                                        transaction={transaction}
                                        field={field}
                                    />
                                ))}
                            </section>
                        )}

                        {transaction.review.category && (
                            <section className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50/70 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/20">
                                <h2 className="font-semibold">
                                    Category needs review
                                </h2>
                                <p>
                                    This Transaction remains included in total
                                    spending and in the Uncategorized reporting
                                    bucket until you assign a Category.
                                </p>
                            </section>
                        )}

                        {transaction.review.refund_relationship_reasons.map(
                            (reason) => (
                                <section
                                    key={reason.name}
                                    className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50/70 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/20"
                                >
                                    <h2 className="font-semibold">
                                        {reason.label}
                                    </h2>
                                    <p>
                                        {reason.name ===
                                        'cumulative_refunds_exceed_purchase'
                                            ? 'The confirmed Refunds remain included. Review the linked purchase before making any separate Correction.'
                                            : 'The purchase has a Receipt Breakdown. No Line Items were copied or inferred for this Refund.'}
                                    </p>
                                </section>
                            ),
                        )}

                        <section className="grid gap-3">
                            <h2 className="font-semibold">Duplicate state</h2>
                            {transaction.duplicate_relationships.length ===
                            0 ? (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    No duplicate relationship.
                                </p>
                            ) : (
                                transaction.duplicate_relationships.map(
                                    (relationship) => (
                                        <div
                                            key={relationship.id}
                                            className="grid gap-3 rounded-lg border p-4"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p className="font-semibold">
                                                        {relationship.status ===
                                                        'suspected'
                                                            ? 'Suspected Duplicate'
                                                            : 'Resolved duplicate pair'}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        Compared with{' '}
                                                        {
                                                            relationship
                                                                .other_transaction
                                                                .merchant_description
                                                        }
                                                    </p>
                                                </div>
                                                {relationship.status ===
                                                    'suspected' && (
                                                    <SuspectedDuplicateDialog
                                                        relationship={
                                                            relationship
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    ),
                                )
                            )}
                        </section>

                        <section className="grid gap-3">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <ShieldCheck className="size-4" /> Provenance
                            </h2>
                            <div className="grid gap-2 rounded-lg border p-4 text-sm">
                                <p>
                                    Category source:{' '}
                                    <span className="font-medium">
                                        {transaction.category?.provenance.source.replace(
                                            '_',
                                            ' ',
                                        ) ?? 'No Category assignment'}
                                    </span>
                                </p>
                                {transaction.category?.provenance.owner && (
                                    <p className="text-muted-foreground">
                                        Assigned by{' '}
                                        {
                                            transaction.category.provenance
                                                .owner.name
                                        }
                                    </p>
                                )}
                                {transaction.category?.provenance
                                    .linked_purchase && (
                                    <p className="text-muted-foreground">
                                        Defaulted from purchase #{' '}
                                        {
                                            transaction.category.provenance
                                                .linked_purchase.id
                                        }
                                        ,{' '}
                                        {
                                            transaction.category.provenance
                                                .linked_purchase
                                                .merchant_description
                                        }
                                        .
                                    </p>
                                )}
                                {transaction.category?.provenance
                                    .merchant_rule && (
                                    <p className="text-muted-foreground">
                                        Merchant Rule #{' '}
                                        {
                                            transaction.category.provenance
                                                .merchant_rule.id
                                        }
                                    </p>
                                )}
                                <p>
                                    {transaction.source_reference_count === 0
                                        ? 'Manual or owner-confirmed entry with no Spending Notification Reference.'
                                        : `${transaction.source_reference_count} Spending Notification ${transaction.source_reference_count === 1 ? 'Reference' : 'References'} retained.`}
                                </p>
                                {transaction.source_references.map(
                                    (reference) => (
                                        <p
                                            key={reference.id}
                                            className="text-muted-foreground"
                                        >
                                            {reference.processing_outcome.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                            {reference.created_at
                                                ? ` · ${reference.created_at.slice(0, 10)}`
                                                : ''}
                                        </p>
                                    ),
                                )}
                            </div>
                        </section>

                        <section className="grid gap-3">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <Link2 className="size-4" /> Refund links
                            </h2>
                            <div className="grid gap-2 rounded-lg border p-4 text-sm">
                                {!transaction.original_purchase &&
                                    transaction.linked_refunds.length === 0 && (
                                        <p className="text-muted-foreground">
                                            No Refund links.
                                        </p>
                                    )}
                                {transaction.original_purchase && (
                                    <p>
                                        Original purchase:{' '}
                                        <span className="font-medium">
                                            {
                                                transaction.original_purchase
                                                    .merchant_description
                                            }
                                        </span>
                                    </p>
                                )}
                                {transaction.linked_refunds.map((refund) => (
                                    <p key={refund.id}>
                                        Linked Refund:{' '}
                                        <span className="font-medium">
                                            {refund.merchant_description}
                                        </span>{' '}
                                        ·{' '}
                                        {formatMinorUnits(
                                            refund.amount_minor,
                                            refund.currency,
                                        )}
                                    </p>
                                ))}
                            </div>
                        </section>

                        <section className="grid gap-3">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <History className="size-4" /> Immutable history
                            </h2>
                            {transaction.corrections.length === 0 &&
                            transaction.state_changes.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    No Corrections or void-state changes have
                                    been recorded.
                                </p>
                            ) : (
                                <div className="grid gap-2">
                                    {transaction.corrections.map(
                                        (correction) => (
                                            <div
                                                key={`correction-${correction.id}`}
                                                className="rounded-lg border p-3 text-sm"
                                            >
                                                <p className="font-medium">
                                                    Correction ·{' '}
                                                    {correction.field_label}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {correction.previous_value}{' '}
                                                    →{' '}
                                                    {correction.corrected_value}{' '}
                                                    · Revision{' '}
                                                    {
                                                        correction.transaction_revision
                                                    }
                                                    {correction.created_at
                                                        ? ` · ${correction.created_at.slice(0, 10)}`
                                                        : ''}
                                                </p>
                                            </div>
                                        ),
                                    )}
                                    {transaction.state_changes.map((change) => (
                                        <div
                                            key={`state-${change.id}`}
                                            className="rounded-lg border p-3 text-sm"
                                        >
                                            <p className="font-medium capitalize">
                                                {change.operation} Transaction
                                            </p>
                                            <p className="text-muted-foreground">
                                                Revision{' '}
                                                {change.result_revision}
                                                {change.created_at
                                                    ? ` · ${change.created_at.slice(0, 10)}`
                                                    : ''}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                </SheetContent>
            )}
        </Sheet>
    );
}
