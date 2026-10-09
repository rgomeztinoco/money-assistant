import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
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
import { formatMinorUnits } from '@/lib/format-minor-units';
import { show } from '@/routes/debts';
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

    return (
        <>
            <Head title="Debts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="type-page-title">Debts</h1>
                        <p className="type-subtitle">
                            Track what you owe and what others owe you.
                        </p>
                    </div>
                    <Button onClick={() => setCreating(true)}>Add debt</Button>
                </header>
                {debts.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>No debts recorded</EmptyTitle>
                            <EmptyDescription>
                                Start with the balance still owed today. You can
                                keep separate loans for the same person or
                                combine them in one debt.
                            </EmptyDescription>
                        </EmptyHeader>
                        <Button
                            variant="outline"
                            onClick={() => setCreating(true)}
                        >
                            Record your first debt
                        </Button>
                    </Empty>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {debts.map((debt) => (
                            <Card key={debt.id}>
                                <CardHeader>
                                    <div className="flex items-center justify-between gap-2">
                                        <CardTitle>
                                            <Link href={show.url(debt.id)}>
                                                {debt.name}
                                            </Link>
                                        </CardTitle>
                                        <Badge variant="secondary">
                                            {debt.status === 'settled'
                                                ? 'Settled'
                                                : 'Active'}
                                        </Badge>
                                    </div>
                                    <CardDescription>
                                        {debt.counterparty} ·{' '}
                                        {debt.direction === 'owed'
                                            ? 'I owe'
                                            : 'Owed to me'}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <p className="type-page-title tabular-nums">
                                        {formatMinorUnits(
                                            debt.balance_minor,
                                            debt.currency,
                                        )}
                                    </p>
                                    <Button
                                        className="mt-4"
                                        variant="outline"
                                        asChild
                                    >
                                        <Link href={show.url(debt.id)}>
                                            View history
                                        </Link>
                                    </Button>
                                    <p className="type-meta">
                                        {debt.target_month} payments{' '}
                                        {formatMinorUnits(
                                            debt.monthly_paid_minor,
                                            debt.currency,
                                        )}
                                        {debt.monthly_target_minor
                                            ? ` of ${formatMinorUnits(debt.monthly_target_minor, debt.currency)} target`
                                            : ' · No target'}
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Add debt</DialogTitle>
                        <DialogDescription>
                            Choose how to group this obligation and enter its
                            current balance.
                        </DialogDescription>
                    </DialogHeader>
                    <DebtForm today={today} />
                </DialogContent>
            </Dialog>
        </>
    );
}
