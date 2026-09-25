import { Deferred, Head, Link, router } from '@inertiajs/react';
import { FileUp } from 'lucide-react';
import { useRef, useState } from 'react';
import { TransactionCategorySelect } from '@/components/transaction-category-select';
import { TransactionEditor } from '@/components/transaction-editor';
import type { EditorTransaction } from '@/components/transaction-editor';
import { TransactionInspector } from '@/components/transaction-inspector';
import { TransactionListFilterControls } from '@/components/transaction-list-filters';
import type { TransactionListFilters } from '@/components/transaction-list-filters';
import { TransactionTable } from '@/components/transaction-table';
import type { TransactionTableRow } from '@/components/transaction-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { ManualTransactionDialog } from '@/pages/breakdown/manual-transaction-dialog';
import { create as createStatementImport } from '@/routes/statement_imports';
import { index } from '@/routes/transactions';
import type { CategoryOption, SelectedTransaction } from '@/types';

type TransactionsFilters = TransactionListFilters & { search: string };

function editorTransaction(
    transaction: SelectedTransaction,
): EditorTransaction {
    return {
        id: transaction.id,
        occurred_on: transaction.occurred_on,
        amount_minor: transaction.amount_minor,
        currency: transaction.currency,
        kind: transaction.kind,
        direction: transaction.direction,
        description: transaction.description,
        income_source: transaction.income_source,
        transfer_purpose: transaction.transfer_purpose,
        category: transaction.category,
        original_spending_id: transaction.original_spending?.id ?? null,
        instrument_label: transaction.instrument_label,
        instrument_last_four: transaction.instrument_last_four,
        split:
            transaction.receipt_breakdown?.line_items.map((lineItem) => ({
                id: lineItem.id,
                amount_minor: lineItem.line_total_minor,
                category: lineItem.category,
            })) ?? null,
    };
}

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
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState<SelectedTransaction | null>(null);
    const scrollPosition = useRef<number | null>(null);

    function visit(url: string): void {
        setLoading(true);
        router.get(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => setLoading(false),
            },
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
            <div className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6 xl:overflow-hidden">
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
                            categoryOptions={category_options}
                        />
                        <Button asChild variant="outline">
                            <Link href={createStatementImport()}>
                                <FileUp /> Import statement
                            </Link>
                        </Button>
                    </div>
                </header>

                <div className="flex shrink-0 flex-wrap items-center gap-2">
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
                        onApply={(applied) =>
                            visit(
                                transactionUrl({
                                    ...filters,
                                    ...applied,
                                }),
                            )
                        }
                    />
                </div>
                <Card className="min-h-0 min-w-0 flex-1 gap-0 overflow-hidden py-0">
                    <div className="flex shrink-0 flex-wrap items-center gap-3 border-b p-4">
                        <div className="flex items-center gap-2">
                            <h2 className="type-section-title">Transactions</h2>
                            <Badge
                                variant="secondary"
                                data-test="transaction-matching-count"
                            >
                                {pagination.total} matching{' '}
                                {pagination.total === 1
                                    ? 'Transaction'
                                    : 'Transactions'}
                            </Badge>
                        </div>
                    </div>
                    <CardContent
                        aria-busy={loading}
                        className="flex min-h-0 min-w-0 flex-1 flex-col p-0"
                    >
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
                            renderCategory={(transaction) => (
                                <TransactionCategorySelect
                                    transaction={transaction}
                                    categoryOptions={category_options}
                                />
                            )}
                            expanded
                        />
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
                            onEdit={setEditing}
                            editorOpen={editing !== null}
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
            {editing && (
                <Dialog open onOpenChange={(open) => !open && setEditing(null)}>
                    <DialogContent className="inset-0 h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 overflow-y-auto rounded-none p-4 sm:top-1/2 sm:left-1/2 sm:h-auto sm:max-h-[90vh] sm:max-w-2xl sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg sm:p-6">
                        <DialogHeader>
                            <DialogTitle>
                                Edit {editing.description}
                            </DialogTitle>
                            <DialogDescription>
                                Record one confirmed movement.
                            </DialogDescription>
                        </DialogHeader>
                        <TransactionEditor
                            key={editing.id}
                            transaction={editorTransaction(editing)}
                            currency={editing.currency}
                            today={today}
                            categoryOptions={category_options}
                            onCancel={() => setEditing(null)}
                            onSaved={() => setEditing(null)}
                        />
                    </DialogContent>
                </Dialog>
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
    viewportConstrained: true,
};
