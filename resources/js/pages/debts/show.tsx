import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { CategoryPickerOption } from '@/components/category-picker';
import { DateText } from '@/components/date-time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { index } from '@/routes/debts';
import { index as transactionsIndex } from '@/routes/transactions';
import { DebtEntryForm } from './debt-entry-form';
import { DebtForm } from './debt-form';
import type { Debt, DebtEntry, DebtTransactionOption } from './types';

export default function DebtDetail({
    debt,
    category_options,
    entries,
    transaction_options,
    today,
}: {
    debt: Debt;
    category_options: CategoryPickerOption[];
    entries: DebtEntry[];
    transaction_options: DebtTransactionOption[];
    today: string;
}) {
    const [editing, setEditing] = useState(false);
    const [recording, setRecording] = useState(false);

    return (
        <>
            <Head title={debt.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link className="type-meta" href={index.url()}>
                            All debts
                        </Link>
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
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Remaining balance{' '}
                            <Badge variant="secondary">
                                {debt.status === 'settled'
                                    ? 'Settled'
                                    : 'Active'}
                            </Badge>
                        </CardTitle>
                        <CardDescription>
                            Opening balance{' '}
                            {formatMinorUnits(
                                debt.opening_balance_minor,
                                debt.currency,
                            )}{' '}
                            on <DateText value={debt.opened_on} />
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p className="type-page-title tabular-nums">
                            {formatMinorUnits(
                                debt.balance_minor,
                                debt.currency,
                            )}
                        </p>
                        <p className="type-meta">
                            {debt.target_month} full payments{' '}
                            {formatMinorUnits(
                                debt.monthly_paid_minor,
                                debt.currency,
                            )}
                            {debt.monthly_target_minor
                                ? ` of ${formatMinorUnits(debt.monthly_target_minor, debt.currency)} target`
                                : ' · No monthly target'}
                        </p>
                        {debt.status === 'settled' &&
                            debt.monthly_target_minor && (
                                <p className="type-meta">
                                    Settled debts do not add an active monthly
                                    commitment.
                                </p>
                            )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                        <CardDescription>
                            Opening balances, interest charges, and adjustments
                            create no Transaction. Voided payments remain in
                            history and do not affect the balance.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {entries.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        No operations recorded
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Record a payment, additional funding, or
                                        a dated correction.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Operation</TableHead>
                                        <TableHead>Details</TableHead>
                                        <TableHead>Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.map((entry) => (
                                        <TableRow key={entry.id}>
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
                                                      : entry.kind ===
                                                          'interest_charge'
                                                        ? 'Interest charge'
                                                        : 'Adjustment'}{' '}
                                                {entry.voided && (
                                                    <Badge variant="outline">
                                                        Voided
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {entry.reason ??
                                                    (entry.transaction_id !==
                                                    null ? (
                                                        <Link
                                                            className="underline"
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
                                                            Transaction #
                                                            {
                                                                entry.transaction_id
                                                            }
                                                        </Link>
                                                    ) : (
                                                        ''
                                                    ))}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {formatMinorUnits(
                                                    entry.amount_minor,
                                                    debt.currency,
                                                )}
                                                {entry.kind === 'repayment' && (
                                                    <p className="type-meta">
                                                        Principal{' '}
                                                        {formatMinorUnits(
                                                            entry.principal_minor ??
                                                                entry.amount_minor,
                                                            debt.currency,
                                                        )}{' '}
                                                        · Interest{' '}
                                                        {formatMinorUnits(
                                                            entry.interest_minor,
                                                            debt.currency,
                                                        )}
                                                    </p>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit debt</DialogTitle>
                        <DialogDescription>
                            Update details or correct the opening baseline. Use
                            a dated adjustment for changes after the opening
                            date.
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
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Record debt operation</DialogTitle>
                        <DialogDescription>
                            Use an existing Transaction for imported payments.
                            Record a manual movement only when it is missing.
                        </DialogDescription>
                    </DialogHeader>
                    <DebtEntryForm
                        debt={debt}
                        categoryOptions={category_options}
                        today={today}
                        transactions={transaction_options}
                        onSaved={() => setRecording(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
