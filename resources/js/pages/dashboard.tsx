import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    CircleCheck,
    Clock3,
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
import { movementDescription } from '@/lib/money-movement';
import { spendingComparisonDescription } from '@/lib/spending-comparison';
import type { SpendingComparison } from '@/lib/spending-comparison';
import {
    categoryBreakdownUrl,
    periodBreakdownUrl,
} from '@/lib/transaction-filter-url';
import { dashboard } from '@/routes';
import { index as breakdownIndex } from '@/routes/breakdown';
import { edit as connectionsEdit } from '@/routes/connections';
import type {
    Currency,
    MovementDirection,
    TransactionKind,
    TransferPurpose,
} from '@/types';

type DashboardPeriod = {
    label: string;
    date_from: string;
    date_to: string;
};

type DashboardSpending = {
    totals: Record<Currency, string>;
    comparisons: Record<Currency, SpendingComparison>;
    category_insights: Record<Currency, CategoryInsight[]>;
};

type PeriodSummary = {
    net_spending_minor: string;
    income_minor: string;
    moved_to_savings_minor: string;
};

type CategoryInsight = {
    category: {
        id: number | null;
        name: string;
    };
    current_total_minor: string;
    previous_total_minor: string;
    change_minor: string;
};

type RecentTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    direction: MovementDirection;
    transfer_purpose: TransferPurpose | null;
    description: string;
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
    return `${transaction.direction === 'credit' ? '+' : '−'}${formatMinorUnits(
        transaction.amount_minor,
        transaction.currency,
    )}`;
}

function categoryChangeDescription({
    insight,
    currency,
}: {
    insight: CategoryInsight;
    currency: Currency;
}) {
    if (insight.change_minor === '0') {
        return 'No change';
    }

    const decreased = insight.change_minor.startsWith('-');
    const absoluteChange = decreased
        ? insight.change_minor.slice(1)
        : insight.change_minor;

    return `${decreased ? 'Down' : 'Up'} ${formatMinorUnits(absoluteChange, currency)}`;
}

export default function Dashboard({
    period,
    comparison_period,
    summaries,
    spending,
    review_queue,
    recent_transactions,
    gmail,
}: {
    period: DashboardPeriod;
    comparison_period: DashboardPeriod;
    summaries: Record<Currency, PeriodSummary>;
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

                <section className="grid gap-4 md:grid-cols-2">
                    {(['PEN', 'USD'] as const).map((currency) => (
                        <Card key={currency} className="h-full">
                            <CardHeader>
                                <div className="flex items-center justify-between gap-3">
                                    <WalletCards className="size-5 text-muted-foreground" />
                                    <Badge variant="secondary">
                                        {period.label}
                                    </Badge>
                                </div>
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    <Link
                                        href={periodBreakdownUrl({
                                            currency,
                                            period,
                                        })}
                                        data-test={`dashboard-spending-${currency.toLowerCase()}`}
                                        className="group grid gap-1 rounded-lg border p-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden sm:col-span-2"
                                    >
                                        <dt className="text-sm text-muted-foreground">
                                            Net Spending
                                        </dt>
                                        <dd className="text-3xl font-semibold tabular-nums transition-colors group-hover:text-primary">
                                            {formatMinorUnits(
                                                summaries[currency]
                                                    .net_spending_minor,
                                                currency,
                                            )}
                                        </dd>
                                    </Link>
                                    <div className="grid gap-1 rounded-lg border p-3">
                                        <dt className="text-sm text-muted-foreground">
                                            Income
                                        </dt>
                                        <dd className="text-lg font-semibold tabular-nums">
                                            {formatMinorUnits(
                                                summaries[currency]
                                                    .income_minor,
                                                currency,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="grid gap-1 rounded-lg border p-3">
                                        <dt className="text-sm text-muted-foreground">
                                            Moved to Savings
                                        </dt>
                                        <dd className="text-lg font-semibold tabular-nums">
                                            {formatMinorUnits(
                                                summaries[currency]
                                                    .moved_to_savings_minor,
                                                currency,
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="text-sm font-medium">
                                    {spendingComparisonDescription({
                                        comparison:
                                            spending.comparisons[currency],
                                        currency,
                                    })}
                                </p>
                                <Link
                                    href={periodBreakdownUrl({
                                        currency,
                                        period: comparison_period,
                                    })}
                                    data-test={`dashboard-comparison-${currency.toLowerCase()}`}
                                    className="flex flex-col gap-1 rounded-lg border bg-muted/20 p-3 text-xs hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <span className="text-muted-foreground">
                                        {comparison_period.label}
                                    </span>
                                    <span className="font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            spending.comparisons[currency]
                                                .previous_total_minor,
                                            currency,
                                        )}
                                    </span>
                                </Link>
                            </CardHeader>
                        </Card>
                    ))}
                </section>

                <section>
                    <Card>
                        <CardHeader>
                            <CardTitle>What changed</CardTitle>
                            <CardDescription>
                                The largest top-level Category changes from{' '}
                                {comparison_period.label}. Select one to inspect
                                its Transactions.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-5 md:grid-cols-2">
                            {(['PEN', 'USD'] as const).map((currency) => (
                                <div key={currency} className="grid gap-2">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-sm font-semibold">
                                            {currency}
                                        </p>
                                        <Badge variant="outline">
                                            {currency} only
                                        </Badge>
                                    </div>
                                    {spending.category_insights[currency]
                                        .length === 0 ? (
                                        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                            Category changes will appear after
                                            Transactions are recorded.
                                        </p>
                                    ) : (
                                        <div className="grid gap-2">
                                            {spending.category_insights[
                                                currency
                                            ].map((insight) => (
                                                <div
                                                    key={
                                                        insight.category.id ??
                                                        'uncategorized'
                                                    }
                                                    className="grid gap-2 rounded-lg border p-3"
                                                >
                                                    <div className="flex items-center justify-between gap-4">
                                                        <span className="truncate text-sm font-medium">
                                                            {
                                                                insight.category
                                                                    .name
                                                            }
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {categoryChangeDescription(
                                                                {
                                                                    insight,
                                                                    currency,
                                                                },
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div className="grid grid-cols-2 gap-2">
                                                        <Link
                                                            href={categoryBreakdownUrl(
                                                                {
                                                                    currency,
                                                                    period,
                                                                    categoryId:
                                                                        insight
                                                                            .category
                                                                            .id,
                                                                },
                                                            )}
                                                            data-test={`dashboard-category-${currency.toLowerCase()}-${insight.category.id ?? 'uncategorized'}`}
                                                            className="grid gap-0.5 rounded-md bg-muted/30 p-2 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                                        >
                                                            <span className="text-xs text-muted-foreground">
                                                                This period
                                                            </span>
                                                            <span className="text-sm font-semibold tabular-nums">
                                                                {formatMinorUnits(
                                                                    insight.current_total_minor,
                                                                    currency,
                                                                )}
                                                            </span>
                                                        </Link>
                                                        <Link
                                                            href={categoryBreakdownUrl(
                                                                {
                                                                    currency,
                                                                    period: comparison_period,
                                                                    categoryId:
                                                                        insight
                                                                            .category
                                                                            .id,
                                                                },
                                                            )}
                                                            data-test={`dashboard-category-previous-${currency.toLowerCase()}-${insight.category.id ?? 'uncategorized'}`}
                                                            className="grid gap-0.5 rounded-md bg-muted/30 p-2 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                                        >
                                                            <span className="text-xs text-muted-foreground">
                                                                Previous period
                                                            </span>
                                                            <span className="text-sm font-semibold tabular-nums">
                                                                {formatMinorUnits(
                                                                    insight.previous_total_minor,
                                                                    currency,
                                                                )}
                                                            </span>
                                                        </Link>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </CardContent>
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
                                <Link href={breakdownIndex()}>
                                    Open Breakdown
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
                                            Add one from Breakdown or connect
                                            Gmail.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {recent_transactions.map((transaction) => {
                                        const isMoneyIn =
                                            transaction.direction === 'credit';
                                        const DirectionIcon = isMoneyIn
                                            ? ArrowDownLeft
                                            : ArrowUpRight;

                                        return (
                                            <Link
                                                key={transaction.id}
                                                href={breakdownIndex({
                                                    query: {
                                                        currency:
                                                            transaction.currency,
                                                        preset: 'custom',
                                                        date_from:
                                                            transaction.occurred_on,
                                                        date_to:
                                                            transaction.occurred_on,
                                                        selected:
                                                            transaction.id,
                                                    },
                                                })}
                                                className="flex items-center gap-3 py-3 first:pt-0 last:pb-0 hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                            >
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                    <DirectionIcon className="size-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">
                                                        {
                                                            transaction.description
                                                        }
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {formatDate(
                                                            transaction.occurred_on,
                                                        )}{' '}
                                                        ·{' '}
                                                        {movementDescription({
                                                            kind: transaction.kind,
                                                            transferPurpose:
                                                                transaction.transfer_purpose,
                                                        })}
                                                    </span>
                                                </span>
                                                <span
                                                    className={`text-sm font-semibold tabular-nums ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
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

                    <Card
                        className={
                            review_queue.outstanding_count > 0 ||
                            gmail.state !== 'connected'
                                ? 'border-amber-300 bg-amber-50/40 dark:border-amber-800 dark:bg-amber-950/10'
                                : undefined
                        }
                    >
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
                            <CardTitle>
                                {review_queue.outstanding_count > 0 ||
                                gmail.state !== 'connected'
                                    ? 'Needs attention'
                                    : 'All caught up'}
                            </CardTitle>
                            <CardDescription>
                                Transactions needing attention now stay in
                                Breakdown. Connection health remains here.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 text-sm">
                            <Link
                                href={breakdownIndex()}
                                data-test="dashboard-review-link"
                                className="flex items-center justify-between gap-3 rounded-lg border bg-background p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            >
                                <span className="flex items-center gap-2 font-medium">
                                    <ReceiptText className="size-4" />
                                    Open Breakdown
                                </span>
                                <Badge
                                    variant={
                                        review_queue.outstanding_count > 0
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {review_queue.outstanding_count}
                                </Badge>
                            </Link>
                            <p className="text-muted-foreground">
                                {gmailPresentation.description}
                            </p>
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
