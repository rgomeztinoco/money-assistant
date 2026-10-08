import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { CategoryPickerOption } from '@/components/category-picker';
import { DateText } from '@/components/date-time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

type DebtDetailProps = { debt: Debt; entries: DebtEntry[]; category_options: CategoryPickerOption[]; today: string };

export default function DebtDetail({ debt, entries, category_options, today }: DebtDetailProps) {
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
                <div className="grid min-h-0 min-w-0 flex-1 gap-5 xl:grid-cols-[minmax(16rem,0.35fr)_minmax(0,1fr)] xl:grid-rows-[minmax(0,1fr)]">
                    <Card
                        className="min-w-0 self-start"
                        aria-label="Debt balance"
                    >
                        <CardHeader>
                            <CardTitle>Balance</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-6">
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
                            <div>
                                <p className="type-meta text-muted-foreground">{debt.target_month} full payments</p>
                                <p className="tabular-nums">{formatMinorUnits(debt.monthly_paid_minor, debt.currency)}</p>
                                <p className="type-meta text-muted-foreground">{debt.monthly_target_minor ? `${formatMinorUnits(debt.monthly_target_minor, debt.currency)} target` : 'No monthly target'}</p>
                            </div>
                            <Badge variant="secondary">
                                {debt.status === 'settled'
                                    ? 'Settled'
                                    : 'Active'}
                            </Badge>
                        </CardContent>
                    </Card>
                    <Card className="min-h-0 min-w-0 gap-4 overflow-hidden">
                        <CardHeader className="shrink-0 flex-row items-center justify-between">
                            <CardTitle>History</CardTitle>
                            <span className="type-meta text-muted-foreground">
                                {entries.length} operations
                            </span>
                        </CardHeader>
                        <CardContent
                            data-test="debt-history"
                            className="min-h-0 flex-1 overflow-auto [&>[data-slot=table-container]]:overflow-visible"
                        >
                            {entries.length === 0 ? (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            No operations recorded
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            Record funding, a payment, or an
                                            adjustment.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <Table className="min-w-[32rem] table-fixed">
                                    <TableHeader className="sticky top-0 bg-card">
                                        <TableRow>
                                            <TableHead className="w-28">
                                                Date
                                            </TableHead>
                                            <TableHead>Operation</TableHead>
                                            <TableHead className="w-32 text-right">
                                                Amount
                                            </TableHead>
                                            <TableHead className="w-16">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
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
                                                        value={
                                                            entry.occurred_on
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {entry.kind === 'repayment'
                                                        ? 'Payment'
                                                        : entry.kind ===
                                                            'funding'
                                                          ? 'Funding'
                                                          : entry.kind === 'interest_charge' ? 'Interest charge' : 'Adjustment'}{' '}
                                                    {entry.voided && (
                                                        <Badge variant="outline">
                                                            Voided
                                                        </Badge>
                                                    )}
                                                    <div
                                                        className="truncate type-meta text-muted-foreground"
                                                        title={
                                                            entry.reason ??
                                                            entry.description ??
                                                            undefined
                                                        }
                                                    >
                                                        {entry.transaction_id ===
                                                        null ? (
                                                            entry.reason
                                                        ) : (
                                                            <Link
                                                                className="underline underline-offset-4"
                                                                title={
                                                                    entry.description ??
                                                                    `Transaction #${entry.transaction_id}`
                                                                }
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
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatMinorUnits(
                                                        entry.amount_minor,
                                                        debt.currency,
                                                    )}
                                                    {entry.kind === 'repayment' && (
                                                        <div className="type-meta text-muted-foreground">
                                                            <p>Principal {formatMinorUnits(entry.principal_minor ?? entry.amount_minor, debt.currency)}</p>
                                                            <p>Interest {formatMinorUnits(entry.interest_minor, debt.currency)}</p>
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Edit ${entry.transaction_id === null ? entry.reason : entry.description}`}
                                                        onClick={() =>
                                                            setEditingEntry(
                                                                entry,
                                                            )
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
                        </CardContent>
                    </Card>
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
                        categoryOptions={category_options}
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
                                ? 'Correct the non-cash operation.'
                                : 'Changes update the original Transaction.'}
                        </DialogDescription>
                    </DialogHeader>
                    {editingEntry && (
                        <DebtEntryForm
                            key={editingEntry.id}
                            debt={debt}
                            today={today}
                            entry={editingEntry}
                            categoryOptions={category_options}
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
