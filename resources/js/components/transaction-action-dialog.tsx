import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    matches,
    store as storeRule,
} from '@/actions/App/Http/Controllers/MerchantRuleController';
import {
    destroy as restoreTransaction,
    store as voidTransaction,
} from '@/actions/App/Http/Controllers/TransactionVoidController';
import { CategoryPicker } from '@/components/category-picker';
import type { CategoryPickerOption } from '@/components/category-picker';
import InputError from '@/components/input-error';
import { TransactionEditor } from '@/components/transaction-editor';
import type { EditorTransaction } from '@/components/transaction-editor';
import type { TransactionAction } from '@/components/transaction-table';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { cn } from '@/lib/utils';
import { CategorySplit } from '@/pages/breakdown/category-split';
import type { BreakdownProps } from '@/pages/breakdown/types';

type MatchPreview = {
    count: number;
    transactions: Array<{
        id: number;
        occurred_on: string;
        description: string;
        amount_minor: string;
        currency: EditorTransaction['currency'];
        category: string | null;
    }>;
};

function MerchantRuleForm({
    transaction,
    categoryOptions,
    onSaved,
    onCancel,
}: {
    transaction: EditorTransaction;
    categoryOptions: CategoryPickerOption[];
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [merchant, setMerchant] = useState(transaction.description);
    const [kind, setKind] = useState(transaction.kind);
    const [currency, setCurrency] = useState(transaction.currency);
    const [applyExisting, setApplyExisting] = useState(false);
    const [preview, setPreview] = useState<MatchPreview | null>(null);
    const [previewError, setPreviewError] = useState(false);
    const [loadingPreview, setLoadingPreview] = useState(false);

    function invalidatePreview(
        nextMerchant: string,
        nextApplyExisting: boolean,
    ): void {
        setPreview(null);
        setPreviewError(nextApplyExisting && nextMerchant.trim() === '');
        setLoadingPreview(nextApplyExisting && nextMerchant.trim() !== '');
    }

    useEffect(() => {
        if (!applyExisting || merchant.trim() === '') {
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            fetch(
                matches.url({
                    query: {
                        merchant,
                        transaction_kind: kind || undefined,
                        currency: currency || undefined,
                    },
                }),
                {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                },
            )
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Preview unavailable');
                    }

                    return response.json() as Promise<MatchPreview>;
                })
                .then((result) => {
                    setPreview(result);
                    setPreviewError(false);
                })
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setPreviewError(true);
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoadingPreview(false);
                    }
                });
        }, 300);

        return () => {
            controller.abort();
            window.clearTimeout(timeout);
        };
    }, [applyExisting, merchant, kind, currency]);

    return (
        <Form
            {...storeRule.form()}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onSaved}
        >
            {({ errors, processing }) => (
                <div className="grid gap-4">
                    <input
                        type="hidden"
                        name="source_transaction_id"
                        value={transaction.id}
                    />
                    <input type="hidden" name="enabled" value="1" />
                    <input
                        type="hidden"
                        name="apply_existing"
                        value={applyExisting ? '1' : '0'}
                    />
                    <p
                        className="rounded-lg bg-muted/40 p-3 type-body"
                        data-test="merchant-rule-source-context"
                    >
                        From Transaction #{transaction.id} ·{' '}
                        {transaction.direction === 'credit'
                            ? 'Money in'
                            : 'Money out'}{' '}
                        ·{' '}
                        {formatMinorUnits(
                            transaction.amount_minor,
                            transaction.currency,
                        )}
                    </p>
                    <div className="grid gap-1.5">
                        <Label htmlFor="action-rule-merchant">Merchant</Label>
                        <Input
                            id="action-rule-merchant"
                            name="merchant"
                            value={merchant}
                            onChange={(event) => {
                                setMerchant(event.target.value);
                                invalidatePreview(
                                    event.target.value,
                                    applyExisting,
                                );
                            }}
                            required
                        />
                        <InputError message={errors.merchant} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="action-rule-category">Category</Label>
                        <CategoryPicker
                            id="action-rule-category"
                            name="category_id"
                            options={categoryOptions}
                            defaultValue={
                                categoryOptions.some(
                                    (option) =>
                                        option.id === transaction.category?.id,
                                )
                                    ? transaction.category?.id.toString()
                                    : ''
                            }
                            required
                            allowEmpty={false}
                        />
                        <InputError message={errors.category_id} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="action-rule-kind">
                                Transaction Kind
                            </Label>
                            <NativeSelect
                                id="action-rule-kind"
                                name="transaction_kind"
                                value={kind}
                                onChange={(event) => {
                                    setKind(event.target.value as typeof kind);
                                    invalidatePreview(merchant, applyExisting);
                                }}
                                options={[
                                    {
                                        value: '',
                                        label: 'Spending and refunds',
                                    },
                                    { value: 'spending', label: 'Spending' },
                                    { value: 'refund', label: 'Refund' },
                                ]}
                            />
                            <InputError message={errors.transaction_kind} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="action-rule-currency">
                                Currency
                            </Label>
                            <NativeSelect
                                id="action-rule-currency"
                                name="currency"
                                value={currency}
                                onChange={(event) => {
                                    setCurrency(
                                        event.target.value as typeof currency,
                                    );
                                    invalidatePreview(merchant, applyExisting);
                                }}
                                options={[
                                    { value: '', label: 'Any currency' },
                                    { value: 'PEN', label: 'PEN' },
                                    { value: 'USD', label: 'USD' },
                                ]}
                            />
                            <InputError message={errors.currency} />
                        </div>
                    </div>
                    <fieldset className="grid gap-2 rounded-lg border p-3">
                        <legend className="px-1 font-medium">Apply rule</legend>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="rule-application-choice"
                                value="future"
                                checked={!applyExisting}
                                onChange={() => {
                                    setApplyExisting(false);
                                    invalidatePreview(merchant, false);
                                }}
                            />{' '}
                            Future Transactions only
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="rule-application-choice"
                                value="existing"
                                data-test="rule-apply-existing"
                                checked={applyExisting}
                                onChange={() => {
                                    setApplyExisting(true);
                                    invalidatePreview(merchant, true);
                                }}
                            />{' '}
                            Existing matches and future Transactions
                        </label>
                    </fieldset>
                    {applyExisting && (
                        <div
                            className="rounded-lg border bg-muted/30 p-3"
                            data-test="merchant-rule-preview"
                        >
                            {loadingPreview && (
                                <p role="status">
                                    Checking existing Transactions…
                                </p>
                            )}
                            {previewError && (
                                <p role="alert">
                                    Could not check matching Transactions. Try
                                    again.
                                </p>
                            )}
                            {preview && (
                                <>
                                    <p className="font-medium">
                                        {preview.count} existing{' '}
                                        {preview.count === 1
                                            ? 'Transaction'
                                            : 'Transactions'}{' '}
                                        {preview.count === 1
                                            ? 'matches'
                                            : 'match'}{' '}
                                        this rule right now
                                    </p>
                                    <p className="type-meta text-muted-foreground">
                                        Existing Categories will be replaced.
                                        Category splits and Voided Transactions
                                        are excluded. Matches are checked again
                                        when you create the rule.
                                    </p>
                                    <ul className="mt-2 max-h-44 overflow-y-auto type-meta">
                                        {preview.transactions.map((match) => (
                                            <li
                                                key={match.id}
                                                className="border-t py-1"
                                            >
                                                #{match.id} ·{' '}
                                                {match.occurred_on} ·{' '}
                                                {match.description} ·{' '}
                                                {formatMinorUnits(
                                                    match.amount_minor,
                                                    match.currency,
                                                )}{' '}
                                                ·{' '}
                                                {match.category ??
                                                    'Uncategorized'}
                                            </li>
                                        ))}
                                    </ul>
                                    {preview.count >
                                        preview.transactions.length && (
                                        <p className="type-meta">
                                            Showing the first{' '}
                                            {preview.transactions.length}{' '}
                                            matches.
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                processing ||
                                (applyExisting &&
                                    (loadingPreview ||
                                        preview === null ||
                                        previewError))
                            }
                        >
                            {processing && <Spinner />} Create Merchant Rule
                        </Button>
                    </DialogFooter>
                </div>
            )}
        </Form>
    );
}

export function TransactionActionDialog({
    transaction,
    action,
    today,
    categoryOptions,
    splitCategoryOptions,
    onClose,
}: {
    transaction: EditorTransaction;
    action: TransactionAction;
    today: string;
    categoryOptions: CategoryPickerOption[];
    splitCategoryOptions: BreakdownProps['category_options'];
    onClose: () => void;
}) {
    const title =
        action === 'edit'
            ? `Edit ${transaction.description}`
            : action === 'split'
              ? `Split ${transaction.description} by Category`
              : action === 'rule'
                ? 'Create a Merchant Rule'
                : action === 'restore'
                  ? `Restore ${transaction.description}`
                  : `Void ${transaction.description}`;

    if (action === 'void' || action === 'restore') {
        return (
            <AlertDialog open onOpenChange={(open) => !open && onClose()}>
                <AlertDialogContent className="data-[size=default]:sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>{title}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {action === 'void'
                                ? 'The Transaction stays in your records but is excluded from summaries.'
                                : 'The Transaction will count in summaries again.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...(action === 'void'
                            ? voidTransaction.form(transaction.id)
                            : restoreTransaction.form(transaction.id))}
                        options={{ preserveScroll: true, preserveState: true }}
                        onSuccess={onClose}
                    >
                        {({ processing, errors }) => (
                            <div className="grid gap-4">
                                <InputError message={errors.void_state} />
                                <AlertDialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={onClose}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant={
                                            action === 'void'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {action === 'void'
                                            ? 'Void Transaction'
                                            : 'Restore Transaction'}
                                    </Button>
                                </AlertDialogFooter>
                            </div>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        );
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                className={cn(
                    'inset-0 flex h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:h-[min(48rem,90dvh)] sm:max-h-[90dvh] sm:w-full sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg',
                    action === 'split'
                        ? 'sm:h-[min(38rem,90dvh)] sm:max-w-4xl'
                        : 'sm:max-w-3xl',
                )}
            >
                <DialogHeader className="shrink-0 border-b px-4 py-4 pr-12 sm:px-6">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {action === 'edit'
                            ? transaction.voided_at
                                ? 'Voided Transaction. Update its recorded details.'
                                : 'Update this confirmed movement.'
                            : action === 'split'
                              ? 'Allocate the full amount across Categories.'
                              : 'Known values are filled from this Transaction. Review and change them as needed.'}
                    </DialogDescription>
                </DialogHeader>
                <div
                    className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6"
                    data-test="transaction-dialog-scroll"
                >
                    {action === 'edit' && (
                        <TransactionEditor
                            key={transaction.id}
                            transaction={transaction}
                            currency={transaction.currency}
                            today={today}
                            categoryOptions={categoryOptions}
                            onCancel={onClose}
                            onSaved={onClose}
                        />
                    )}
                    {action === 'split' && (
                        <CategorySplit
                            key={`${transaction.id}-${transaction.split?.map((row) => row.id).join('-') ?? 'none'}`}
                            transaction={transaction}
                            categoryOptions={splitCategoryOptions}
                        />
                    )}
                    {action === 'rule' && (
                        <MerchantRuleForm
                            key={transaction.id}
                            transaction={transaction}
                            categoryOptions={categoryOptions}
                            onCancel={onClose}
                            onSaved={onClose}
                        />
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
