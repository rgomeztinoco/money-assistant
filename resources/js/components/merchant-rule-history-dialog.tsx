import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    applyExisting,
    ruleMatches,
} from '@/actions/App/Http/Controllers/MerchantRuleController';
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

type RuleMatchPreview = {
    merchant: string;
    category: string;
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
    onClose,
}: {
    ruleId: number;
    onClose: () => void;
}) {
    const [preview, setPreview] = useState<RuleMatchPreview | null>(null);
    const [previewError, setPreviewError] = useState(false);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        const controller = new AbortController();

        fetch(ruleMatches.url(ruleId), {
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
    }, [ruleId, retry]);

    return (
        <AlertDialog open onOpenChange={(open) => !open && onClose()}>
            <AlertDialogContent className="flex max-h-[min(42rem,90dvh)] flex-col sm:max-w-xl">
                <AlertDialogHeader className="shrink-0">
                    <AlertDialogTitle>
                        Apply Merchant Rule to previous Transactions?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Review the matches before replacing their Categories.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...applyExisting.form(ruleId)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onClose}
                    className="flex min-h-0 flex-col gap-4"
                >
                    {({ processing }) => (
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
                                            {preview.merchant} right now.
                                        </p>
                                        <p className="type-meta text-muted-foreground">
                                            Matching Categories will change to{' '}
                                            {preview.category}. Category splits
                                            and Voided Transactions are
                                            excluded. Matches are checked again
                                            when you apply the rule.
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
                            <AlertDialogFooter className="shrink-0">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        !preview ||
                                        preview.count === 0
                                    }
                                    data-test="apply-merchant-rule-history"
                                >
                                    {processing && <Spinner />}
                                    Apply to previous Transactions
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
