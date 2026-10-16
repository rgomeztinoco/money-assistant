import { Head, Link, useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatCompactMonthYear } from '@/lib/date-presentation';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { index, settle, show } from '@/routes/debts';
import { DebtForm } from './debt-form';
import type { Debt } from './types';

export default function Debts({
    debts,
    today,
}: {
    debts: Debt[];
    today: string;
}) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Debt | null>(null);
    const [settling, setSettling] = useState<Debt | null>(null);
    const settlement = useForm<{ settlement?: string }>({});
    const [status, setStatus] = useState('active');
    const [direction, setDirection] = useState('all');
    const [search, setSearch] = useState('');
    const counts = {
        active: debts.filter((debt) => debt.status === 'active').length,
        settled: debts.filter((debt) => debt.status === 'settled').length,
    };
    const visible = debts.filter(
        (debt) =>
            debt.status === status &&
            (direction === 'all' || debt.direction === direction) &&
            `${debt.name} ${debt.counterparty}`
                .toLocaleLowerCase()
                .includes(search.toLocaleLowerCase()),
    );
    const totals = (['PEN', 'USD'] as const).map((currency) => {
        const amounts = {
            owed: 0n,
            receivable: 0n,
            outgoingTarget: 0n,
            incomingTarget: 0n,
            paid: 0n,
            collected: 0n,
        };

        debts
            .filter((debt) => debt.currency === currency)
            .forEach((debt) => {
                const owed = debt.direction === 'owed';
                amounts[owed ? 'owed' : 'receivable'] += BigInt(
                    debt.balance_minor,
                );
                amounts[owed ? 'paid' : 'collected'] += BigInt(
                    debt.monthly_paid_minor,
                );

                if (debt.status === 'active') {
                    amounts[owed ? 'outgoingTarget' : 'incomingTarget'] +=
                        BigInt(debt.monthly_target_minor ?? '0');
                }
            });

        return { currency, ...amounts };
    });

    return (
        <>
            <Head title="Debts" />
            <div className="flex min-h-0 flex-1 flex-col gap-5 p-4 md:p-6 xl:overflow-hidden">
                <header className="flex shrink-0 items-center justify-between gap-4">
                    <div>
                        <h1 className="type-page-title">Debts</h1>
                        <p className="type-subtitle">
                            {counts.active} active · {counts.settled} settled
                        </p>
                    </div>
                    <Button onClick={() => setCreating(true)}>Add debt</Button>
                </header>
                <div className="grid min-h-0 min-w-0 flex-1 gap-5 xl:grid-cols-[minmax(16rem,0.35fr)_minmax(0,1fr)] xl:grid-rows-[minmax(0,1fr)]">
                    <div className="flex min-h-0 min-w-0 flex-col gap-5 xl:overflow-y-auto">
                        <Card
                            className="shrink-0 gap-4"
                            aria-label="Monthly debt plan"
                        >
                            <CardHeader className="gap-1">
                                <CardTitle>Monthly plan</CardTitle>
                                <p className="type-meta text-muted-foreground">
                                    {formatCompactMonthYear(today)}
                                </p>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
                                {totals.map((total) => (
                                    <section
                                        key={total.currency}
                                        className="grid gap-2"
                                    >
                                        <h2 className="type-meta font-medium">
                                            {total.currency}
                                        </h2>
                                        <dl className="grid grid-cols-2 gap-3">
                                            {(
                                                [
                                                    [
                                                        'Planned out',
                                                        'outgoingTarget',
                                                        'paid',
                                                        'paid',
                                                    ],
                                                    [
                                                        'Planned in',
                                                        'incomingTarget',
                                                        'collected',
                                                        'collected',
                                                    ],
                                                ] as const
                                            ).map(
                                                ([
                                                    label,
                                                    target,
                                                    payment,
                                                    paymentLabel,
                                                ]) => (
                                                    <div
                                                        key={target}
                                                        className="min-w-0"
                                                    >
                                                        <dt className="type-meta text-muted-foreground">
                                                            {label}
                                                        </dt>
                                                        <dd
                                                            className="type-section-title tabular-nums"
                                                            data-test={`${target}-${total.currency}`}
                                                        >
                                                            {formatMinorUnits(
                                                                total[
                                                                    target
                                                                ].toString(),
                                                                total.currency,
                                                            )}
                                                        </dd>
                                                        <dd
                                                            className="type-meta text-muted-foreground"
                                                            data-test={`${payment}-${total.currency}`}
                                                        >
                                                            {formatMinorUnits(
                                                                total[
                                                                    payment
                                                                ].toString(),
                                                                total.currency,
                                                            )}{' '}
                                                            {paymentLabel}
                                                        </dd>
                                                    </div>
                                                ),
                                            )}
                                        </dl>
                                    </section>
                                ))}
                            </CardContent>
                        </Card>
                        <Card
                            className="shrink-0 gap-4"
                            aria-label="Debt balances"
                        >
                            <CardHeader>
                                <CardTitle>Balances</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
                                {totals.map((total) => (
                                    <section
                                        key={total.currency}
                                        className="grid gap-2"
                                    >
                                        <h2 className="type-meta font-medium">
                                            {total.currency}
                                        </h2>
                                        <dl className="grid grid-cols-2 gap-3">
                                            {(
                                                [
                                                    ['I owe', 'owed'],
                                                    [
                                                        'Owed to me',
                                                        'receivable',
                                                    ],
                                                ] as const
                                            ).map(([label, balance]) => (
                                                <div
                                                    key={balance}
                                                    className="min-w-0"
                                                >
                                                    <dt className="type-meta text-muted-foreground">
                                                        {label}
                                                    </dt>
                                                    <dd
                                                        className="font-medium tabular-nums"
                                                        data-test={`${balance}-${total.currency}`}
                                                    >
                                                        {formatMinorUnits(
                                                            total[
                                                                balance
                                                            ].toString(),
                                                            total.currency,
                                                        )}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </section>
                                ))}
                            </CardContent>
                        </Card>
                    </div>
                    <Card className="min-h-0 min-w-0 gap-4 overflow-hidden">
                        <CardHeader className="shrink-0 gap-4">
                            <CardTitle>Your debts</CardTitle>
                            <div className="flex flex-wrap items-center gap-3">
                                <Tabs value={status} onValueChange={setStatus}>
                                    <TabsList>
                                        <TabsTrigger value="active">
                                            Active
                                            <Badge variant="secondary">
                                                {counts.active}
                                            </Badge>
                                        </TabsTrigger>
                                        <TabsTrigger value="settled">
                                            Settled
                                            <Badge variant="secondary">
                                                {counts.settled}
                                            </Badge>
                                        </TabsTrigger>
                                    </TabsList>
                                </Tabs>
                                <Input
                                    aria-label="Search debts"
                                    placeholder="Search debts"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    className="min-w-0 flex-1 basis-40"
                                />
                                <NativeSelect
                                    className="sm:w-44"
                                    aria-label="Debt direction"
                                    value={direction}
                                    onChange={(event) =>
                                        setDirection(event.target.value)
                                    }
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'Both directions',
                                        },
                                        { value: 'owed', label: 'I owe' },
                                        {
                                            value: 'receivable',
                                            label: 'Owed to me',
                                        },
                                    ]}
                                />
                            </div>
                        </CardHeader>
                        <CardContent
                            data-test="debt-list"
                            className="min-h-0 flex-1 overflow-auto [&>[data-slot=table-container]]:overflow-visible"
                        >
                            {visible.length === 0 ? (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            {debts.length === 0
                                                ? 'No debts recorded'
                                                : 'No matching debts'}
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            {debts.length === 0
                                                ? 'Add a debt with its current balance.'
                                                : 'Try another search, direction, or tab.'}
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <Table className="min-w-[26rem] table-fixed">
                                    <TableHeader className="sticky top-0 bg-card">
                                        <TableRow>
                                            <TableHead>Debt</TableHead>
                                            <TableHead className="w-36 text-right">
                                                Balance
                                            </TableHead>
                                            <TableHead className="w-40 text-right">
                                                Monthly target
                                            </TableHead>
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {visible.map((debt) => (
                                            <TableRow key={debt.id}>
                                                <TableCell>
                                                    <Link
                                                        className="block truncate font-medium underline-offset-4 hover:underline"
                                                        title={debt.name}
                                                        href={show.url(debt.id)}
                                                    >
                                                        {debt.name}
                                                    </Link>
                                                    <p
                                                        className="truncate type-meta text-muted-foreground"
                                                        title={`${debt.direction === 'owed' ? 'I owe' : 'Owed to me'} · ${debt.counterparty}`}
                                                    >
                                                        {debt.direction ===
                                                        'owed'
                                                            ? 'I owe'
                                                            : 'Owed to me'}
                                                        {' · '}
                                                        {debt.counterparty}
                                                    </p>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatMinorUnits(
                                                        debt.balance_minor,
                                                        debt.currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {debt.monthly_target_minor ===
                                                    null ? (
                                                        <span className="text-muted-foreground">
                                                            No target
                                                        </span>
                                                    ) : (
                                                        formatMinorUnits(
                                                            debt.monthly_target_minor,
                                                            debt.currency,
                                                        )
                                                    )}
                                                    <p className="type-meta text-muted-foreground">
                                                        {formatMinorUnits(
                                                            debt.monthly_paid_minor,
                                                            debt.currency,
                                                        )}{' '}
                                                        {debt.direction ===
                                                        'owed'
                                                            ? 'paid'
                                                            : 'collected'}{' '}
                                                        this month
                                                    </p>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            asChild
                                                        >
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label={`Actions for ${debt.name}`}
                                                            >
                                                                <MoreHorizontal />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuGroup>
                                                                <DropdownMenuItem
                                                                    onSelect={() =>
                                                                        setEditing(
                                                                            debt,
                                                                        )
                                                                    }
                                                                >
                                                                    Edit
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    disabled={
                                                                        debt.status ===
                                                                        'settled'
                                                                    }
                                                                    onSelect={() => {
                                                                        settlement.clearErrors();
                                                                        setSettling(
                                                                            debt,
                                                                        );
                                                                    }}
                                                                >
                                                                    Settle debt
                                                                </DropdownMenuItem>
                                                            </DropdownMenuGroup>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
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
            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit debt</DialogTitle>
                        <DialogDescription>{editing?.name}</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <DebtForm
                            key={editing.id}
                            debt={editing}
                            today={today}
                            onSaved={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
            <AlertDialog
                open={settling !== null}
                onOpenChange={(open) => {
                    if (!open && !settlement.processing) {
                        setSettling(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Settle {settling?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Clear the current balance of{' '}
                            {settling &&
                                formatMinorUnits(
                                    settling.balance_minor,
                                    settling.currency,
                                )}{' '}
                            with a non-cash adjustment. No payment will be
                            recorded.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <InputError message={settlement.errors.settlement} />
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={settlement.processing}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={settlement.processing}
                            onClick={(event) => {
                                event.preventDefault();

                                if (settling) {
                                    settlement.post(settle.url(settling.id), {
                                        preserveScroll: true,
                                        onSuccess: () => setSettling(null),
                                    });
                                }
                            }}
                        >
                            Settle debt
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Add debt</DialogTitle>
                        <DialogDescription>
                            Enter the current balance.
                        </DialogDescription>
                    </DialogHeader>
                    <DebtForm today={today} />
                </DialogContent>
            </Dialog>
        </>
    );
}

Debts.layout = {
    breadcrumbs: [{ title: 'Debts', href: index() }],
    viewportConstrained: true,
};
