import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    CircleCheck,
    Clock3,
    ListChecks,
    Mail,
    ReceiptText,
    RefreshCw,
    WalletCards,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { dashboard } from '@/routes';
import { edit as connectionsEdit } from '@/routes/connections';
import { index as reviewQueueIndex } from '@/routes/review_queue';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Currency, TransactionKind } from '@/types';

type DashboardPeriod = {
    label: string;
    date_from: string;
    date_to: string;
};

type DashboardSpending = {
    totals: Record<Currency, string>;
};

type RecentTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
};

type GmailStatus = {
    state:
        | 'disconnected'
        | 'connected'
        | 'stale'
        | 'check_failed'
        | 'reauthorization_required';
    account_identity: string | null;
    last_successful_sync_at: string | null;
};

const gmailStatePresentation: Record<
    GmailStatus['state'],
    { label: string; description: string; icon: typeof Mail }
> = {
    disconnected: {
        label: 'Not connected',
        description: 'Connect Gmail to import Spending Notifications.',
        icon: Mail,
    },
    connected: {
        label: 'Connected',
        description: 'Gmail is ready to synchronize Spending Notifications.',
        icon: CircleCheck,
    },
    stale: {
        label: 'Sync delayed',
        description: 'The latest Gmail synchronization is delayed.',
        icon: Clock3,
    },
    check_failed: {
        label: 'Check failed',
        description: 'The latest Gmail connection check did not succeed.',
        icon: RefreshCw,
    },
    reauthorization_required: {
        label: 'Reconnect required',
        description: 'Reconnect Gmail to resume synchronization.',
        icon: RefreshCw,
    },
};

function periodQuery(period: DashboardPeriod) {
    return {
        date_from: period.date_from,
        date_to: period.date_to,
    };
}

function formatDate(date: string) {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
}

function formatSyncTimestamp(timestamp: string | null) {
    if (timestamp === null) {
        return 'No successful sync yet';
    }

    return `Last synced ${new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(timestamp))}`;
}

function transactionAmount(transaction: RecentTransaction) {
    const amount =
        transaction.kind === 'refund'
            ? `-${transaction.amount_minor}`
            : transaction.amount_minor;

    return formatMinorUnits(amount, transaction.currency);
}

export default function Dashboard({
    period,
    spending,
    review_queue,
    recent_transactions,
    gmail,
}: {
    period: DashboardPeriod;
    spending: DashboardSpending;
    review_queue: { outstanding_count: number };
    recent_transactions: RecentTransaction[];
    gmail: GmailStatus;
}) {
    const gmailPresentation = gmailStatePresentation[gmail.state];
    const GmailIcon = gmailPresentation.icon;

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Dashboard
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Your spending and recent activity for {period.label}.
                    </p>
                </div>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {(['PEN', 'USD'] as const).map((currency) => (
                        <Link
                            key={currency}
                            href={transactionsIndex({
                                query: {
                                    ...periodQuery(period),
                                    currency,
                                },
                            })}
                            data-test={`dashboard-spending-${currency.toLowerCase()}`}
                            className="group rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            <Card className="h-full transition-colors group-hover:border-primary/40 group-hover:bg-muted/20">
                                <CardHeader>
                                    <div className="flex items-center justify-between gap-3">
                                        <WalletCards className="size-5 text-muted-foreground" />
                                        <Badge variant="secondary">
                                            {period.label}
                                        </Badge>
                                    </div>
                                    <CardDescription>
                                        {currency} current-period total
                                    </CardDescription>
                                    <CardTitle className="text-3xl tabular-nums">
                                        {formatMinorUnits(
                                            spending.totals[currency],
                                            currency,
                                        )}
                                    </CardTitle>
                                </CardHeader>
                            </Card>
                        </Link>
                    ))}

                    <Card
                        className={
                            review_queue.outstanding_count > 0
                                ? 'border-amber-300 bg-amber-50/40 md:col-span-2 xl:col-span-1 dark:border-amber-800 dark:bg-amber-950/10'
                                : 'md:col-span-2 xl:col-span-1'
                        }
                    >
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <ListChecks className="size-5 text-muted-foreground" />
                                <Badge variant="outline">Review Queue</Badge>
                            </div>
                            <CardDescription>
                                Details waiting for your review
                            </CardDescription>
                            <CardTitle className="text-3xl tabular-nums">
                                {review_queue.outstanding_count}
                            </CardTitle>
                        </CardHeader>
                        <CardFooter>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link
                                    href={reviewQueueIndex()}
                                    data-test="dashboard-review-link"
                                >
                                    Open Review Queue
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>
                </section>

                <section className="grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                    <Card>
                        <CardHeader className="flex-row items-start justify-between gap-4">
                            <div className="grid gap-1.5">
                                <CardTitle>Recent Transactions</CardTitle>
                                <CardDescription>
                                    Your latest confirmed money movements.
                                </CardDescription>
                            </div>
                            <Button asChild size="sm" variant="ghost">
                                <Link href={transactionsIndex()}>
                                    View all
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {recent_transactions.length === 0 ? (
                                <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-6 text-center">
                                    <ReceiptText className="size-8 text-muted-foreground" />
                                    <div className="grid gap-1">
                                        <p className="font-medium">
                                            No Transactions yet
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Record one from the Transactions
                                            page or connect Gmail.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {recent_transactions.map((transaction) => {
                                        const KindIcon =
                                            transaction.kind === 'refund'
                                                ? ArrowDownLeft
                                                : ArrowUpRight;

                                        return (
                                            <Link
                                                key={transaction.id}
                                                href={transactionsIndex({
                                                    query: {
                                                        selected:
                                                            transaction.id,
                                                    },
                                                })}
                                                className="flex items-center gap-3 py-3 first:pt-0 last:pb-0 hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                            >
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                    <KindIcon className="size-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">
                                                        {
                                                            transaction.merchant_description
                                                        }
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {formatDate(
                                                            transaction.occurred_on,
                                                        )}
                                                    </span>
                                                </span>
                                                <span
                                                    className={`text-sm font-semibold tabular-nums ${transaction.kind === 'refund' ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                                                >
                                                    {transactionAmount(
                                                        transaction,
                                                    )}
                                                </span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <GmailIcon className="size-5 text-muted-foreground" />
                                <Badge
                                    variant={
                                        gmail.state === 'connected'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {gmailPresentation.label}
                                </Badge>
                            </div>
                            <CardTitle>Gmail</CardTitle>
                            <CardDescription>
                                {gmailPresentation.description}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm">
                            {gmail.account_identity !== null && (
                                <p className="truncate font-medium">
                                    {gmail.account_identity}
                                </p>
                            )}
                            <p className="text-muted-foreground">
                                {formatSyncTimestamp(
                                    gmail.last_successful_sync_at,
                                )}
                            </p>
                        </CardContent>
                        <CardFooter>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link
                                    href={connectionsEdit()}
                                    data-test="dashboard-gmail-link"
                                >
                                    Manage connection
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
