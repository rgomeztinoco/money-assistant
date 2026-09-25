import { Deferred, Head, Link, router } from '@inertiajs/react';
import { FileUp, Hash } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import { TransactionInspector } from '@/components/transaction-inspector';
import { TransactionListFilterControls } from '@/components/transaction-list-filters';
import type { TransactionListFilters } from '@/components/transaction-list-filters';
import { TransactionTable } from '@/components/transaction-table';
import type { TransactionTableRow } from '@/components/transaction-table';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { ManualTransactionDialog } from '@/pages/breakdown/manual-transaction-dialog';
import { create as createStatementImport } from '@/routes/statement_imports';
import { index } from '@/routes/transactions';
import type { CategoryOption, SelectedTransaction } from '@/types';

type TransactionsFilters = TransactionListFilters & { search: string };

type Pagination = {
    current_page: number;
    last_page: number;
    total: number;
};

export type TransactionsIndexProps = {
    today: string;
    category_options: CategoryOption[];
    transactions: TransactionTableRow[];
    pagination: Pagination;
    filters: TransactionsFilters;
    selected_transaction_id: number | null;
    selected_transaction?: SelectedTransaction | null;
};

function transactionUrl(
    filters: TransactionsFilters,
    page?: number,
    selected?: number,
): string {
    return index.url({
        query: {
            search: filters.search || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            currency: filters.currency || undefined,
            amount_min: filters.amount_min || undefined,
            amount_max: filters.amount_max || undefined,
            kinds: filters.kinds.length > 0 ? filters.kinds : undefined,
            page: page && page > 1 ? page : undefined,
            selected,
        },
    });
}

export default function TransactionsIndex({
    today,
    category_options,
    transactions,
    pagination,
    filters,
    selected_transaction_id,
    selected_transaction,
}: TransactionsIndexProps) {
    const [lookupId, setLookupId] = useState('');
    const [lookupError, setLookupError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const scrollPosition = useRef<number | null>(null);

    function visit(url: string, preserveState = false): void {
        setLoading(true);
        router.get(
            url,
            {},
            {
                preserveScroll: true,
                preserveState,
                onFinish: () => setLoading(false),
            },
        );
    }

    function findById(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (
            !/^[1-9]\d*$/.test(lookupId) ||
            !Number.isSafeInteger(Number(lookupId))
        ) {
            setLookupError('Enter a valid Transaction ID.');

            return;
        }

        setLookupError(null);
        scrollPosition.current = window.scrollY;
        visit(
            transactionUrl(filters, pagination.current_page, Number(lookupId)),
            true,
        );
    }

    function closeDetails(): void {
        const position = scrollPosition.current;

        router.get(
            transactionUrl(filters, pagination.current_page),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setLoading(false);

                    if (position !== null) {
                        requestAnimationFrame(() =>
                            window.scrollTo(0, position),
                        );
                        scrollPosition.current = null;
                    }
                },
            },
        );
    }

    return (
        <>
            <Head title="Transactions" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-1">
                        <h1 className="type-page-title">Transactions</h1>
                        <p className="type-subtitle">
                            Search and manage Transactions across your history.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <ManualTransactionDialog
                            currency={filters.currency ?? 'PEN'}
                            today={today}
                        />
                        <Button asChild variant="outline">
                            <Link href={createStatementImport()}>
                                <FileUp /> Import statement
                            </Link>
                        </Button>
                    </div>
                </header>

                <Card className="min-w-0">
                    <CardContent className="grid gap-4">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                            <div className="min-w-0 flex-1">
                                <TransactionListFilterControls
                                    key={JSON.stringify(filters)}
                                    search={filters.search}
                                    filters={filters}
                                    instantSearch={false}
                                    includeDates
                                    includeCurrency
                                    onSearch={(search) =>
                                        visit(
                                            transactionUrl({
                                                ...filters,
                                                search,
                                            }),
                                        )
                                    }
                                    onApply={(applied, search) =>
                                        visit(
                                            transactionUrl({
                                                ...filters,
                                                ...applied,
                                                search,
                                            }),
                                        )
                                    }
                                />
                            </div>
                            <form onSubmit={findById} className="flex gap-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="transaction-id">
                                        Find by ID
                                    </Label>
                                    <Input
                                        id="transaction-id"
                                        aria-label="Transaction ID"
                                        inputMode="numeric"
                                        placeholder="Transaction ID"
                                        value={lookupId}
                                        onChange={(event) => {
                                            setLookupId(event.target.value);
                                            setLookupError(null);
                                        }}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="self-end"
                                    data-test="transaction-id-submit"
                                >
                                    <Hash /> Find by ID
                                </Button>
                            </form>
                        </div>
                        {lookupError && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {lookupError}
                            </p>
                        )}
                        <div aria-busy={loading} className="min-w-0">
                            {loading && (
                                <p
                                    role="status"
                                    className="flex items-center gap-2 px-4 type-meta"
                                >
                                    <Spinner /> Loading Transactions…
                                </p>
                            )}
                            <TransactionTable
                                transactions={transactions}
                                total={pagination.total}
                                page={pagination.current_page}
                                onPageChange={(page) =>
                                    visit(transactionUrl(filters, page))
                                }
                                rowHref={(transaction) =>
                                    transactionUrl(
                                        filters,
                                        pagination.current_page,
                                        transaction.id,
                                    )
                                }
                                onBeforeOpen={() => {
                                    scrollPosition.current = window.scrollY;
                                }}
                                rowTestId={(transaction) =>
                                    'transaction-' + transaction.id
                                }
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            {selected_transaction_id !== null && (
                <Deferred
                    data="selected_transaction"
                    fallback={
                        <div className="fixed inset-y-0 right-0 z-50 grid w-full max-w-2xl place-items-center border-l bg-background/95">
                            <Spinner className="size-6" />
                        </div>
                    }
                >
                    {selected_transaction ? (
                        <TransactionInspector
                            transaction={selected_transaction}
                            categoryOptions={category_options}
                            onOpenChange={(open) => {
                                if (!open) {
                                    closeDetails();
                                }
                            }}
                        />
                    ) : (
                        <Dialog
                            open
                            onOpenChange={(open) => {
                                if (!open) {
                                    closeDetails();
                                }
                            }}
                        >
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        Transaction not found
                                    </DialogTitle>
                                    <DialogDescription>
                                        No accessible Transaction has that ID.
                                        Your search and filters are unchanged.
                                    </DialogDescription>
                                </DialogHeader>
                                <Button type="button" onClick={closeDetails}>
                                    Close
                                </Button>
                            </DialogContent>
                        </Dialog>
                    )}
                </Deferred>
            )}
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Transactions',
            href: index(),
        },
    ],
};
