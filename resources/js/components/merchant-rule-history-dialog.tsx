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
            <AlertDialogContent className="flex max-h-[min(42rem,90dvh)] min-w-0 flex-col overflow-hidden p-4 sm:max-w-xl sm:p-6">
                <AlertDialogHeader className="min-w-0 shrink-0">
                    <AlertDialogTitle>
                        {draft
                            ? 'Create merchant rule'
                            : 'Update previous transactions?'}
                    </AlertDialogTitle>
                    <AlertDialogDescription className="wrap-anywhere">
                        {draft
                            ? `Use the selected category for future transactions from ${draft.merchant}.`
                            : 'Apply this rule to the matching transactions below.'}
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
                    className="flex min-h-0 min-w-0 flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="min-h-0 overflow-y-auto type-body wrap-anywhere">
                                {preview === null && !previewError && (
                                    <p
                                        role="status"
                                        className="flex items-center gap-2"
                                    >
                                        <Spinner /> Finding matches…
                                    </p>
                                )}
                                {previewError && (
                                    <div className="grid gap-2">
                                        <p role="alert">
                                            Could not load matching
                                            transactions.
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
                                            {preview.count === 1
                                                ? 'matching transaction'
                                                : 'matching transactions'}
                                        </p>
                                        <p className="type-meta text-muted-foreground">
                                            {draft
                                                ? 'You can also apply the selected category to these previous transactions.'
                                                : `Their category will change to ${preview.category}.`}{' '}
                                            Split and voided transactions are
                                            excluded.
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
                            <AlertDialogFooter className="grid min-w-0 shrink-0 grid-cols-1 gap-2 sm:grid-cols-[auto_minmax(0,1fr)]">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                    disabled={processing}
                                    className="order-last sm:order-none"
                                >
                                    Cancel
                                </Button>
                                <div className="grid min-w-0 gap-2 sm:grid-cols-2">
                                    {draft && (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            onClick={() =>
                                                setApplyHistory(false)
                                            }
                                            data-test="save-merchant-rule-future"
                                        >
                                            Save for future
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
                                        className={
                                            draft
                                                ? undefined
                                                : 'sm:col-span-2 sm:justify-self-end'
                                        }
                                    >
                                        {processing && <Spinner />}
                                        {draft
                                            ? 'Save and update past'
                                            : 'Apply to previous'}
                                    </Button>
                                </div>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
