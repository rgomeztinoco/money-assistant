import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    applyExisting,
    ruleMatches,
    matches,
    store,
} from '@/actions/App/Http/Controllers/MerchantRuleController';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';
import type { MerchantRuleDraft } from '@/types/ui';

type RuleMatchPreview = {
    merchant: string;
    category?: string;
    count: number;
    transactions: Array<{
        id: number;
        occurred_on: string;
        description: string;
        amount_minor: string;
        currency: Currency;
        category: string | null;
    }>;
};

export function MerchantRuleHistoryDialog({
    ruleId,
    draft,
    onClose,
}: {
    ruleId?: number;
    draft?: MerchantRuleDraft;
    onClose: () => void;
}) {
    const [preview, setPreview] = useState<RuleMatchPreview | null>(null);
    const [previewError, setPreviewError] = useState(false);
    const [retry, setRetry] = useState(0);
    const [applyHistory, setApplyHistory] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        const url = draft
            ? matches.url({
                  query: {
                      merchant: draft.merchant,
                      transaction_kind: draft.transaction_kind,
                      currency: draft.currency,
                  },
              })
            : ruleMatches.url(ruleId!);

        fetch(url, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Preview unavailable');
                }

                return response.json() as Promise<RuleMatchPreview>;
            })
            .then((result) => {
                setPreview(result);
                setPreviewError(false);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setPreviewError(true);
                }
            });

        return () => controller.abort();
    }, [ruleId, draft, retry]);

    return (
        <AlertDialog open onOpenChange={(open) => !open && onClose()}>
            <AlertDialogContent className="flex max-h-[min(42rem,90dvh)] flex-col sm:max-w-xl">
                <AlertDialogHeader className="shrink-0">
                    <AlertDialogTitle>
                        {draft
                            ? 'Create merchant rule'
                            : 'Apply Merchant Rule to previous Transactions?'}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {draft
                            ? `Save a rule for ${draft.merchant}. Choose whether to update previous Transactions too.`
                            : 'Review the matches before replacing their Categories.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...(draft ? store.form() : applyExisting.form(ruleId!))}
                    transform={(data) =>
                        draft
                            ? {
                                  ...data,
                                  ...draft,
                                  enabled: true,
                                  apply_existing: applyHistory,
                              }
                            : data
                    }
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onClose}
                    className="flex min-h-0 flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="min-h-0 overflow-y-auto type-body">
                                {preview === null && !previewError && (
                                    <p
                                        role="status"
                                        className="flex items-center gap-2"
                                    >
                                        <Spinner /> Checking previous
                                        Transactions…
                                    </p>
                                )}
                                {previewError && (
                                    <div className="grid gap-2">
                                        <p role="alert">
                                            Could not check previous
                                            Transactions.
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                setPreviewError(false);
                                                setRetry((value) => value + 1);
                                            }}
                                        >
                                            Try again
                                        </Button>
                                    </div>
                                )}
                                {preview && (
                                    <div className="grid gap-3">
                                        <p>
                                            <strong>{preview.count}</strong>{' '}
                                            previous{' '}
                                            {preview.count === 1
                                                ? 'Transaction matches'
                                                : 'Transactions match'}{' '}
                                            {draft?.merchant ??
                                                preview.merchant}{' '}
                                            right now.
                                        </p>
                                        <p className="type-meta text-muted-foreground">
                                            {draft
                                                ? 'Matching Categories will change to the Category you selected.'
                                                : `Matching Categories will change to ${preview.category}.`}{' '}
                                            Category splits and Voided
                                            Transactions are excluded. Matches
                                            are checked again when you apply the
                                            rule.
                                        </p>
                                        <ul className="divide-y border-y type-meta">
                                            {preview.transactions.map(
                                                (transaction) => (
                                                    <li
                                                        key={transaction.id}
                                                        className="py-2"
                                                    >
                                                        #{transaction.id} ·{' '}
                                                        {
                                                            transaction.occurred_on
                                                        }{' '}
                                                        ·{' '}
                                                        {
                                                            transaction.description
                                                        }{' '}
                                                        ·{' '}
                                                        {formatMinorUnits(
                                                            transaction.amount_minor,
                                                            transaction.currency,
                                                        )}{' '}
                                                        ·{' '}
                                                        {transaction.category ??
                                                            'Uncategorized'}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                        {preview.count >
                                            preview.transactions.length && (
                                            <p className="type-meta">
                                                Showing the first{' '}
                                                {preview.transactions.length}{' '}
                                                matches.
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                            <InputError message={Object.values(errors)[0]} />
                            <AlertDialogFooter className="shrink-0">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                {draft && (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        onClick={() => setApplyHistory(false)}
                                        data-test="save-merchant-rule-future"
                                    >
                                        Save for future only
                                    </Button>
                                )}
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        !preview ||
                                        preview.count === 0
                                    }
                                    data-test="apply-merchant-rule-history"
                                    onClick={() => setApplyHistory(true)}
                                >
                                    {processing && <Spinner />}
                                    {draft
                                        ? 'Save and apply to previous Transactions'
                                        : 'Apply to previous Transactions'}
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
