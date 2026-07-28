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
    Sparkles,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { store as confirmReceiptBreakdown } from '@/actions/App/Http/Controllers/ReceiptBreakdownConfirmationController';
import { update as updateReceiptBreakdown } from '@/actions/App/Http/Controllers/ReceiptBreakdownController';
import { store as attachReceiptProposal } from '@/actions/App/Http/Controllers/ReceiptProposalAttachmentController';
import { store as resolveSuspectedDuplicate } from '@/actions/App/Http/Controllers/SuspectedDuplicateResolutionController';
import { update as updateCategory } from '@/actions/App/Http/Controllers/TransactionCategoryController';
import { update } from '@/actions/App/Http/Controllers/TransactionFieldReviewController';
import { store as previewLearnedRule } from '@/actions/App/Http/Controllers/TransactionLearnedRulePreviewController';
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
    ReceiptLineItem,
    SelectedTransaction,
    CategoryOption,
} from '@/types';

const receiptLineItemRoleOptions: Array<{
    value: ReceiptLineItem['role'];
    label: string;
}> = [
    { value: 'purchased_item', label: 'Purchased item' },
    { value: 'tax', label: 'Tax' },
    { value: 'discount', label: 'Discount' },
    { value: 'tip', label: 'Tip' },
    { value: 'fee', label: 'Fee' },
    { value: 'rounding', label: 'Rounding' },
    { value: 'other_adjustment', label: 'Other adjustment' },
    { value: 'unidentified', label: 'Unidentified known amount' },
];

function receiptLineItemRoleLabel(role: ReceiptLineItem['role']): string {
    return (
        receiptLineItemRoleOptions.find((option) => option.value === role)
            ?.label ?? role
    );
}

function isAdjustmentRole(role: ReceiptLineItem['role']): boolean {
    return role !== 'purchased_item' && role !== 'unidentified';
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
    const draft = transaction.receipt_breakdown.draft;
    const confirmed = transaction.receipt_breakdown.confirmed;
    const [draftLineItems, setDraftLineItems] = useState(() =>
        (draft?.line_items ?? []).map((lineItem) => ({
            ...lineItem,
            clientId: lineItem.id,
        })),
    );
    const draftIsDirty =
        draft !== null &&
        JSON.stringify(
            draftLineItems.map((lineItem) => ({
                id: lineItem.id || null,
                description: lineItem.description,
                role: lineItem.role,
                quantity: lineItem.quantity,
                unit_price_minor: lineItem.unit_price_minor,
                line_total_minor: lineItem.line_total_minor,
                category_id: lineItem.category?.id ?? null,
                related_line_item_id: lineItem.related_line_item_id,
            })),
        ) !==
            JSON.stringify(
                draft.line_items.map((lineItem) => ({
                    id: lineItem.id,
                    description: lineItem.description,
                    role: lineItem.role,
                    quantity: lineItem.quantity,
                    unit_price_minor: lineItem.unit_price_minor,
                    line_total_minor: lineItem.line_total_minor,
                    category_id: lineItem.category?.id ?? null,
                    related_line_item_id: lineItem.related_line_item_id,
                })),
            );

    function updateDraftLineItem(
        clientId: string,
        field:
            | 'description'
            | 'role'
            | 'quantity'
            | 'unit_price_minor'
            | 'line_total_minor'
            | 'category_id'
            | 'related_line_item_id',
        value: string,
    ) {
        setDraftLineItems((lineItems) =>
            lineItems.map((lineItem) => {
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

                if (field === 'related_line_item_id') {
                    const relatedLineItem = lineItems.find(
                        (candidate) => candidate.id === value,
                    );

                    return {
                        ...lineItem,
                        related_line_item_id: value || null,
                        category:
                            lineItem.category ??
                            relatedLineItem?.category ??
                            null,
                    };
                }

                if (field === 'role') {
                    const role = value as ReceiptLineItem['role'];

                    return {
                        ...lineItem,
                        role,
                        category:
                            role === 'unidentified' ? null : lineItem.category,
                        related_line_item_id: isAdjustmentRole(role)
                            ? lineItem.related_line_item_id
                            : null,
                        requires_review: role === 'unidentified',
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
                {confirmed && <Badge variant="outline">Active</Badge>}
                {draft && <Badge variant="secondary">Draft</Badge>}
            </div>

            {confirmed && (
                <div className="grid gap-3 rounded-lg border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <p className="font-medium">
                            Confirmed revision {confirmed.revision}
                        </p>
                        <p className="tabular-nums">
                            {formatMinorUnits(
                                confirmed.total_minor,
                                transaction.currency,
                            )}
                        </p>
                    </div>
                    <div className="grid gap-2">
                        {confirmed.line_items.map((lineItem) => (
                            <div
                                key={lineItem.id}
                                className="flex items-start justify-between gap-3 border-t pt-2 text-sm first:border-0 first:pt-0"
                            >
                                <div>
                                    <p className="font-medium">
                                        {lineItem.description}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {receiptLineItemRoleLabel(
                                            lineItem.role,
                                        )}{' '}
                                        ·{' '}
                                        {lineItem.category?.name ??
                                            'Uncategorized'}
                                        {lineItem.quantity !== null && (
                                            <> · Quantity {lineItem.quantity}</>
                                        )}
                                        {lineItem.unit_price_minor !== null && (
                                            <>
                                                {' '}
                                                · Unit{' '}
                                                {formatMinorUnits(
                                                    lineItem.unit_price_minor,
                                                    transaction.currency,
                                                )}
                                            </>
                                        )}
                                        {lineItem.related_line_item_id !==
                                            null && <> · Item-specific</>}
                                    </p>
                                </div>
                                <p className="tabular-nums">
                                    {formatMinorUnits(
                                        lineItem.line_total_minor,
                                        transaction.currency,
                                    )}
                                </p>
                            </div>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        These Line Items replace the Transaction Category in
                        reports; they do not add another contribution.
                    </p>
                </div>
            )}

            {draft && (
                <div className="grid gap-3 rounded-lg border border-primary/30 bg-primary/[0.03] p-4">
                    <div className="grid gap-1 text-sm">
                        <p className="font-medium">
                            Draft revision {draft.revision}
                        </p>
                        <p className="text-muted-foreground">
                            Draft total:{' '}
                            {formatMinorUnits(
                                draft.total_minor,
                                transaction.currency,
                            )}{' '}
                            · Delta:{' '}
                            {formatMinorUnits(
                                draft.delta_minor,
                                transaction.currency,
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Draft Line Items do not affect reports.
                        </p>
                    </div>
                    <Form
                        {...updateReceiptBreakdown.form(draft.id)}
                        options={{ preserveScroll: true, preserveState: true }}
                        className="grid gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_revision"
                                    value={draft.revision}
                                />
                                {draftLineItems.map((lineItem, index) => (
                                    <div
                                        key={lineItem.clientId}
                                        className="grid gap-3 rounded-md border bg-background p-3 sm:grid-cols-2"
                                    >
                                        <input
                                            type="hidden"
                                            name={`line_items[${index}][id]`}
                                            value={lineItem.id}
                                        />
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`receipt-line-${lineItem.clientId}-role`}
                                            >
                                                Role
                                            </Label>
                                            <NativeSelect
                                                id={`receipt-line-${lineItem.clientId}-role`}
                                                name={`line_items[${index}][role]`}
                                                value={lineItem.role}
                                                onChange={(event) =>
                                                    updateDraftLineItem(
                                                        lineItem.clientId,
                                                        'role',
                                                        event.target.value,
                                                    )
                                                }
                                                options={
                                                    receiptLineItemRoleOptions
                                                }
                                            />
                                        </div>
                                        {isAdjustmentRole(lineItem.role) && (
                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor={`receipt-line-${lineItem.clientId}-related-item`}
                                                >
                                                    Applies to
                                                </Label>
                                                <NativeSelect
                                                    id={`receipt-line-${lineItem.clientId}-related-item`}
                                                    name={`line_items[${index}][related_line_item_id]`}
                                                    value={
                                                        lineItem.related_line_item_id ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        updateDraftLineItem(
                                                            lineItem.clientId,
                                                            'related_line_item_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            value: '',
                                                            label: 'Whole receipt',
                                                        },
                                                        ...draftLineItems
                                                            .filter(
                                                                (candidate) =>
                                                                    candidate.role ===
                                                                        'purchased_item' &&
                                                                    candidate.id !==
                                                                        '',
                                                            )
                                                            .map(
                                                                (
                                                                    candidate,
                                                                ) => ({
                                                                    value: candidate.id,
                                                                    label: candidate.description,
                                                                }),
                                                            ),
                                                    ]}
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    Item-specific adjustments
                                                    default to the purchased
                                                    item&apos;s Category.
                                                </p>
                                            </div>
                                        )}
                                        <div className="grid gap-2">
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
                                                    updateDraftLineItem(
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
                                                Printed quantity
                                            </Label>
                                            <Input
                                                id={`receipt-line-${lineItem.clientId}-quantity`}
                                                name={`line_items[${index}][quantity]`}
                                                inputMode="decimal"
                                                value={lineItem.quantity ?? ''}
                                                onChange={(event) =>
                                                    updateDraftLineItem(
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
                                                Printed unit price in minor
                                                units
                                            </Label>
                                            <Input
                                                id={`receipt-line-${lineItem.clientId}-unit-price`}
                                                name={`line_items[${index}][unit_price_minor]`}
                                                type="number"
                                                step="1"
                                                value={
                                                    lineItem.unit_price_minor ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    updateDraftLineItem(
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
                                                min={
                                                    lineItem.role ===
                                                    'purchased_item'
                                                        ? '1'
                                                        : undefined
                                                }
                                                step="1"
                                                value={
                                                    lineItem.line_total_minor
                                                }
                                                onChange={(event) =>
                                                    updateDraftLineItem(
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
                                            {lineItem.role ===
                                                'unidentified' && (
                                                <input
                                                    type="hidden"
                                                    name={`line_items[${index}][category_id]`}
                                                    value=""
                                                />
                                            )}
                                            <NativeSelect
                                                id={`receipt-line-${lineItem.clientId}-category`}
                                                name={
                                                    lineItem.role ===
                                                    'unidentified'
                                                        ? undefined
                                                        : `line_items[${index}][category_id]`
                                                }
                                                disabled={
                                                    lineItem.role ===
                                                    'unidentified'
                                                }
                                                value={
                                                    lineItem.category?.id.toString() ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    updateDraftLineItem(
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
                                            {lineItem.role ===
                                                'unidentified' && (
                                                <p className="text-xs text-muted-foreground">
                                                    Unidentified amounts stay
                                                    Uncategorized and in the
                                                    Review Queue.
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="w-fit text-destructive sm:col-span-2"
                                            disabled={
                                                draftLineItems.length === 1
                                            }
                                            onClick={() =>
                                                setDraftLineItems((lineItems) =>
                                                    lineItems.filter(
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
                                    disabled={draftLineItems.length >= 200}
                                    onClick={() => {
                                        const clientId = crypto.randomUUID();

                                        setDraftLineItems((lineItems) => [
                                            ...lineItems,
                                            {
                                                id: '',
                                                clientId,
                                                description: '',
                                                role: 'purchased_item' as const,
                                                quantity: null,
                                                unit_price_minor: null,
                                                line_total_minor: '1',
                                                category: null,
                                                related_line_item_id: null,
                                                requires_review: false,
                                            },
                                        ]);
                                    }}
                                >
                                    <Plus /> Add Line Item
                                </Button>
                                <InputError
                                    message={
                                        errors.line_items ??
                                        errors.expected_revision
                                    }
                                />
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Save draft revision
                                </Button>
                            </>
                        )}
                    </Form>
                    <Form
                        {...confirmReceiptBreakdown.form(draft.id)}
                        options={{ preserveScroll: true, preserveState: true }}
                        className="grid gap-1"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_revision"
                                    value={draft.revision}
                                />
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        draft.delta_minor !== '0' ||
                                        draftIsDirty
                                    }
                                >
                                    {processing ? <Spinner /> : <Check />}
                                    Confirm exact breakdown
                                </Button>
                                <InputError
                                    message={
                                        errors.reconciliation ??
                                        errors.expected_revision
                                    }
                                />
                                {draftIsDirty && (
                                    <p className="text-xs text-muted-foreground">
                                        Save these edits as a new draft revision
                                        before confirming.
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </div>
            )}

            {!draft && transaction.receipt_proposals.length > 0 && (
                <div className="grid gap-3 rounded-lg border border-dashed p-4">
                    <div>
                        <p className="font-medium">
                            Unattached Receipt Proposals
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Select one explicitly. Similarity never attaches a
                            proposal automatically.
                        </p>
                    </div>
                    {transaction.receipt_proposals.map((proposal) => (
                        <Form
                            key={proposal.id}
                            {...attachReceiptProposal.form(transaction.id)}
                            options={{
                                preserveScroll: true,
                                preserveState: true,
                            }}
                            className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="receipt_proposal_id"
                                        value={proposal.id}
                                    />
                                    <div className="text-sm">
                                        <p className="font-medium">
                                            {
                                                proposal.proposed_merchant_description
                                            }
                                        </p>
                                        <p className="text-muted-foreground">
                                            {formatMinorUnits(
                                                proposal.proposed_amount_minor,
                                                transaction.currency,
                                            )}{' '}
                                            · {proposal.line_item_count}{' '}
                                            {proposal.line_item_count === 1
                                                ? 'item'
                                                : 'items'}
                                        </p>
                                        <InputError
                                            message={errors.receipt_proposal_id}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <ReceiptText />
                                        )}
                                        Attach proposal
                                    </Button>
                                </>
                            )}
                        </Form>
                    ))}
                </div>
            )}

            {!draft &&
                !confirmed &&
                transaction.receipt_proposals.length === 0 && (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        No Receipt Breakdown or compatible unattached proposal.
                    </p>
                )}
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
          (transaction.receipt_breakdown.confirmed?.line_items.filter(
              (lineItem) =>
                  lineItem.category === null || lineItem.requires_review,
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
                            {transaction.learned_rule_candidate && (
                                <div className="grid gap-3 rounded-lg border border-primary/30 bg-primary/5 p-4">
                                    <div className="flex items-start gap-3">
                                        <Sparkles className="mt-0.5 size-4 shrink-0 text-primary" />
                                        <div className="grid gap-1">
                                            <h2 className="font-semibold">
                                                Create an exact Learned Rule?
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Future{' '}
                                                <span className="capitalize">
                                                    {
                                                        transaction
                                                            .learned_rule_candidate
                                                            .transaction_kind
                                                    }
                                                </span>{' '}
                                                Transactions in{' '}
                                                {
                                                    transaction
                                                        .learned_rule_candidate
                                                        .currency
                                                }{' '}
                                                matching “
                                                {
                                                    transaction
                                                        .learned_rule_candidate
                                                        .merchant_pattern
                                                }
                                                ” exactly would use{' '}
                                                {
                                                    transaction
                                                        .learned_rule_candidate
                                                        .category_name
                                                }
                                                . Nothing is activated
                                                automatically, and existing
                                                Transactions do not change.
                                            </p>
                                        </div>
                                    </div>
                                    <Form
                                        {...previewLearnedRule.form(
                                            transaction.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ errors, processing }) => (
                                            <div className="grid gap-1">
                                                <input
                                                    type="hidden"
                                                    name="expected_revision"
                                                    value={transaction.revision}
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    className="w-fit"
                                                    disabled={processing}
                                                >
                                                    {processing ? (
                                                        <Spinner />
                                                    ) : (
                                                        <Sparkles />
                                                    )}
                                                    Preview exact rule
                                                </Button>
                                                <InputError
                                                    message={
                                                        errors.transaction_id
                                                    }
                                                />
                                            </div>
                                        )}
                                    </Form>
                                </div>
                            )}
                        </section>

                        <ReceiptBreakdownSection
                            key={`${transaction.receipt_breakdown.draft?.id ?? 'none'}-${transaction.receipt_breakdown.draft?.revision ?? 0}-${transaction.receipt_breakdown.confirmed?.id ?? 'none'}`}
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
                                    .learned_rule && (
                                    <p className="text-muted-foreground">
                                        Learned Rule #{' '}
                                        {
                                            transaction.category.provenance
                                                .learned_rule.id
                                        }{' '}
                                        · Revision{' '}
                                        {
                                            transaction.category.provenance
                                                .learned_rule.revision
                                        }
                                    </p>
                                )}
                                {transaction.category?.provenance
                                    .bulk_action && (
                                    <p className="text-muted-foreground">
                                        Historical application group #{' '}
                                        {
                                            transaction.category.provenance
                                                .bulk_action.id
                                        }
                                    </p>
                                )}
                                {transaction.category?.provenance.ai && (
                                    <div className="grid gap-1 text-muted-foreground">
                                        <p>
                                            {
                                                transaction.category.provenance
                                                    .ai.classifier_version
                                            }{' '}
                                            ·{' '}
                                            {
                                                transaction.category.provenance
                                                    .ai.confidence
                                            }
                                            % model confidence ·{' '}
                                            {transaction.category.provenance.ai.outcome.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </p>
                                        <p>
                                            {
                                                transaction.category.provenance
                                                    .ai.explanation
                                            }
                                        </p>
                                    </div>
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
