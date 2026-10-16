import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { DateText } from '@/components/date-time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { index, show } from '@/routes/debts';
import { index as transactionsIndex } from '@/routes/transactions';
import { DebtEntryForm } from './debt-entry-form';
import { DebtForm } from './debt-form';
import type { Debt, DebtEntry } from './types';

type DebtDetailProps = { debt: Debt; entries: DebtEntry[]; today: string };

export default function DebtDetail({ debt, entries, today }: DebtDetailProps) {
    const [editing, setEditing] = useState(false);
    const [recording, setRecording] = useState(false);
    const [editingEntry, setEditingEntry] = useState<DebtEntry | null>(null);

    return (
        <>
            <Head title={debt.name} />
            <div className="flex min-h-0 flex-1 flex-col gap-5 p-4 md:p-6 xl:overflow-hidden">
                <header className="flex shrink-0 flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="type-page-title">{debt.name}</h1>
                        <p className="type-subtitle">
                            {debt.counterparty} ·{' '}
                            {debt.direction === 'owed' ? 'I owe' : 'Owed to me'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setEditing(true)}
                        >
                            Edit debt
                        </Button>
                        <Button onClick={() => setRecording(true)}>
                            Record operation
                        </Button>
                    </div>
                </header>
                <section
                    className="flex shrink-0 flex-wrap items-center justify-between gap-5 border-y py-4"
                    aria-label="Debt balance"
                >
                    <div>
                        <p className="type-meta text-muted-foreground">
                            Remaining balance
                        </p>
                        <p className="type-page-title tabular-nums">
                            {formatMinorUnits(
                                debt.balance_minor,
                                debt.currency,
                            )}
                        </p>
                    </div>
                    <div>
                        <p className="type-meta text-muted-foreground">
                            Opening balance
                        </p>
                        <p className="tabular-nums">
                            {formatMinorUnits(
                                debt.opening_balance_minor,
                                debt.currency,
                            )}
                        </p>
                        <p className="type-meta text-muted-foreground">
                            <DateText value={debt.opened_on} />
                        </p>
                    </div>
                    <Badge variant="secondary">
                        {debt.status === 'settled' ? 'Settled' : 'Active'}
                    </Badge>
                </section>
                <div className="flex shrink-0 items-center justify-between">
                    <h2 className="type-section-title">History</h2>
                    <span className="type-meta text-muted-foreground">
                        {entries.length} operations
                    </span>
                </div>
                <div
                    data-test="debt-history"
                    className="min-h-0 flex-1 overflow-auto rounded-lg border [&>[data-slot=table-container]]:overflow-visible"
                >
                    {entries.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>No operations recorded</EmptyTitle>
                                <EmptyDescription>
                                    Record funding, a payment, or an adjustment.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <Table>
                            <TableHeader className="sticky top-0 bg-background">
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Operation</TableHead>
                                    <TableHead>Details</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.map((entry) => (
                                    <TableRow
                                        key={entry.id}
                                        data-test={`debt-entry-${entry.id}`}
                                    >
                                        <TableCell>
                                            <DateText
                                                value={entry.occurred_on}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {entry.kind === 'repayment'
                                                ? 'Payment'
                                                : entry.kind === 'funding'
                                                  ? 'Funding'
                                                  : 'Adjustment'}{' '}
                                            {entry.voided && (
                                                <Badge variant="outline">
                                                    Voided
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {entry.transaction_id === null ? (
                                                entry.reason
                                            ) : (
                                                <Link
                                                    className="underline underline-offset-4"
                                                    href={transactionsIndex.url(
                                                        {
                                                            query: {
                                                                selected:
                                                                    entry.transaction_id,
                                                                search: String(
                                                                    entry.transaction_id,
                                                                ),
                                                            },
                                                        },
                                                    )}
                                                >
                                                    {entry.description ??
                                                        `Transaction #${entry.transaction_id}`}
                                                </Link>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMinorUnits(
                                                entry.amount_minor,
                                                debt.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Edit ${entry.kind === 'adjustment' ? entry.reason : entry.description}`}
                                                onClick={() =>
                                                    setEditingEntry(entry)
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </div>
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit debt</DialogTitle>
                        <DialogDescription>
                            Update the debt details and opening balance.
                        </DialogDescription>
                    </DialogHeader>
                    <DebtForm
                        debt={debt}
                        today={today}
                        onSaved={() => setEditing(false)}
                    />
                </DialogContent>
            </Dialog>
            <Dialog open={recording} onOpenChange={setRecording}>
                <DialogContent
                    className="max-h-[90svh] overflow-y-auto"
                    onEscapeKeyDown={(event) => {
                        if (
                            event.target instanceof Element &&
                            event.target.closest(
                                '[data-slot="popover-content"]',
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Record debt operation</DialogTitle>
                        <DialogDescription>
                            Link a Transaction or record a missing movement.
                        </DialogDescription>
                    </DialogHeader>
                    <DebtEntryForm
                        debt={debt}
                        today={today}
                        onSaved={() => setRecording(false)}
                    />
                </DialogContent>
            </Dialog>
            <Dialog
                open={editingEntry !== null}
                onOpenChange={(open) => !open && setEditingEntry(null)}
            >
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit debt operation</DialogTitle>
                        <DialogDescription>
                            {editingEntry?.transaction_id === null
                                ? 'Correct the balance adjustment.'
                                : 'Changes update the original Transaction.'}
                        </DialogDescription>
                    </DialogHeader>
                    {editingEntry && (
                        <DebtEntryForm
                            key={editingEntry.id}
                            debt={debt}
                            today={today}
                            entry={editingEntry}
                            onSaved={() => setEditingEntry(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

DebtDetail.layout = ({ debt }: DebtDetailProps) => ({
    breadcrumbs: [
        { title: 'Debts', href: index() },
        { title: debt.name, href: show(debt.id) },
    ],
    viewportConstrained: true,
});
