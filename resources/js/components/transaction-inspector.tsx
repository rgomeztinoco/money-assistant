import { Form } from '@inertiajs/react';
import {
    Check,
    CircleAlert,
    CircleOff,
    Link2,
    Plus,
    ReceiptText,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import {
    destroy as removeReceiptBreakdown,
    update as saveReceiptBreakdown,
} from '@/actions/App/Http/Controllers/ReceiptBreakdownController';
import { update as updateTransaction } from '@/actions/App/Http/Controllers/TransactionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { CategoryOption, SelectedTransaction } from '@/types';

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

function TransactionEditForm({
    transaction,
    categoryOptions,
}: {
    transaction: SelectedTransaction;
    categoryOptions: CategoryOption[];
}) {
    return (
        <Form
            key={transaction.id}
            {...updateTransaction.form(transaction.id)}
            options={{ preserveScroll: true, preserveState: true }}
            className="grid gap-4 rounded-lg border p-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-date`}
                            >
                                Edit occurrence date
                            </Label>
                            <Input
                                id={`transaction-${transaction.id}-date`}
                                name="occurred_on"
                                type="date"
                                defaultValue={transaction.occurred_on}
                            />
                            <InputError message={errors.occurred_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-amount`}
                            >
                                Edit amount in minor units
                            </Label>
                            <Input
                                id={`transaction-${transaction.id}-amount`}
                                name="amount_minor"
                                type="number"
                                min="1"
                                step="1"
                                inputMode="numeric"
                                defaultValue={transaction.amount_minor}
                            />
                            <InputError message={errors.amount_minor} />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-currency`}
                            >
                                Edit currency
                            </Label>
                            <NativeSelect
                                id={`transaction-${transaction.id}-currency`}
                                name="currency"
                                defaultValue={transaction.currency}
                                options={[
                                    { value: 'USD', label: 'USD' },
                                    { value: 'PEN', label: 'PEN' },
                                ]}
                            />
                            <InputError message={errors.currency} />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-kind`}
                            >
                                Edit Transaction kind
                            </Label>
                            <NativeSelect
                                id={`transaction-${transaction.id}-kind`}
                                name="kind"
                                defaultValue={transaction.kind}
                                options={[
                                    { value: 'purchase', label: 'Purchase' },
                                    { value: 'refund', label: 'Refund' },
                                ]}
                            />
                            <InputError message={errors.kind} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label
                            htmlFor={`transaction-${transaction.id}-merchant`}
                        >
                            Edit merchant or description
                        </Label>
                        <Input
                            id={`transaction-${transaction.id}-merchant`}
                            name="merchant_description"
                            maxLength={255}
                            defaultValue={transaction.merchant_description}
                        />
                        <InputError message={errors.merchant_description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-instrument`}
                            >
                                Edit payment instrument
                            </Label>
                            <Input
                                id={`transaction-${transaction.id}-instrument`}
                                name="payment_instrument_label"
                                maxLength={100}
                                defaultValue={
                                    transaction.payment_instrument_label ?? ''
                                }
                                placeholder="Visa, cash, checking"
                            />
                            <InputError
                                message={errors.payment_instrument_label}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-last-four`}
                            >
                                Edit last four digits
                            </Label>
                            <Input
                                id={`transaction-${transaction.id}-last-four`}
                                name="payment_instrument_last_four"
                                inputMode="numeric"
                                pattern="[0-9]{4}"
                                maxLength={4}
                                defaultValue={
                                    transaction.payment_instrument_last_four ??
                                    ''
                                }
                            />
                            <InputError
                                message={errors.payment_instrument_last_four}
                            />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-category`}
                            >
                                Edit Category
                            </Label>
                            <NativeSelect
                                id={`transaction-${transaction.id}-category`}
                                name="category_id"
                                defaultValue={
                                    transaction.category?.id.toString() ?? ''
                                }
                                options={[
                                    { value: '', label: 'Uncategorized' },
                                    ...categoryOptions.map((category) => ({
                                        value: category.id.toString(),
                                        label: category.path,
                                    })),
                                ]}
                            />
                            <InputError message={errors.category_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`transaction-${transaction.id}-purchase`}
                            >
                                Edit original purchase
                            </Label>
                            <NativeSelect
                                id={`transaction-${transaction.id}-purchase`}
                                name="original_purchase_id"
                                defaultValue={
                                    transaction.original_purchase?.id.toString() ??
                                    ''
                                }
                                options={[
                                    { value: '', label: 'No Refund link' },
                                    ...transaction.purchase_options.map(
                                        (purchase) => ({
                                            value: purchase.id.toString(),
                                            label: `${purchase.occurred_on} · ${purchase.merchant_description} · ${purchase.currency}`,
                                        }),
                                    ),
                                ]}
                            />
                            <InputError message={errors.original_purchase_id} />
                        </div>
                    </div>

                    {transaction.review.fields.length > 0 && (
                        <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                            Changing a flagged value clears that field&apos;s
                            current review flag.
                        </p>
                    )}

                    {transaction.receipt_breakdown !== null && (
                        <div className="flex items-start gap-2 rounded-md border p-3">
                            <input
                                id={`transaction-${transaction.id}-remove-breakdown`}
                                name="remove_receipt_breakdown"
                                type="checkbox"
                                value="1"
                                className="mt-0.5 size-4 rounded border-input"
                            />
                            <div className="grid gap-1">
                                <Label
                                    htmlFor={`transaction-${transaction.id}-remove-breakdown`}
                                >
                                    Remove Receipt Breakdown if the amount
                                    changes
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    This explicitly removes the current Line
                                    Items because they would no longer
                                    reconcile.
                                </p>
                                <InputError
                                    message={errors.remove_receipt_breakdown}
                                />
                            </div>
                        </div>
                    )}

                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Save Transaction
                    </Button>
                </>
            )}
        </Form>
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
          (transaction.review.category ? 1 : 0) +
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
                            Transaction #{transaction.id} · Edit the current
                            ledger record directly.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="grid gap-6 px-4 pb-6">
                        <section className="grid gap-3">
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Edit current Transaction
                            </h2>
                            <TransactionEditForm
                                transaction={transaction}
                                categoryOptions={categoryOptions}
                            />
                        </section>

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
                        </section>

                        <ReceiptBreakdownSection
                            key={`${transaction.receipt_breakdown?.id ?? 'none'}-${transaction.receipt_breakdown?.line_items.map((lineItem) => lineItem.id).join('-') ?? ''}`}
                            transaction={transaction}
                            categoryOptions={categoryOptions}
                        />

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
                                            ? 'The confirmed Refunds remain included. Review the linked purchase and edit the current Refund if needed.'
                                            : 'The purchase has a Receipt Breakdown. No Line Items were copied or inferred for this Refund.'}
                                    </p>
                                </section>
                            ),
                        )}

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
                    </div>
                </SheetContent>
            )}
        </Sheet>
    );
}
