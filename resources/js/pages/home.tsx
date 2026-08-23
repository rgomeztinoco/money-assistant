import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CircleAlert,
    CircleDollarSign,
    Landmark,
    PiggyBank,
    ReceiptText,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    categoryBreakdownUrl,
    periodBreakdownUrl,
} from '@/lib/transaction-filter-url';
import { home } from '@/routes';
import { index as trendsIndex } from '@/routes/trends';
import type { Currency } from '@/types';

type Period = {
    label: string;
    date_from: string;
    date_to: string;
};

type Summary = {
    net_spending_minor: string;
    income_minor: string;
    moved_to_savings_minor: string;
};

type MaterialChange = {
    category: { id: number | null; name: string };
    current_total_minor: string;
    typical_total_minor: string;
    change_minor: string;
    comparison_periods: Period[];
};

type Briefing = {
    currency: Currency;
    period: Period;
    coverage: {
        date_from: string;
        date_to: string;
        transaction_count: number;
    };
    summary: Summary;
    material_change: MaterialChange | null;
    input_request: { transaction_count: number } | null;
};

type SummaryItem = {
    label: string;
    value: keyof Summary;
    focus: 'net_spending' | 'income' | 'savings';
    icon: typeof CircleDollarSign;
};

const summaryItems: SummaryItem[] = [
    {
        label: 'Net Spending',
        value: 'net_spending_minor',
        focus: 'net_spending',
        icon: CircleDollarSign,
    },
    {
        label: 'Income',
        value: 'income_minor',
        focus: 'income',
        icon: Landmark,
    },
    {
        label: 'Moved to Savings',
        value: 'moved_to_savings_minor',
        focus: 'savings',
        icon: PiggyBank,
    },
];

function shortDate(date: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
}

function coverageText(briefing: Briefing): string {
    const transactionLabel =
        briefing.coverage.transaction_count === 1
            ? 'Transaction'
            : 'Transactions';

    return `${shortDate(briefing.coverage.date_from)} to ${shortDate(briefing.coverage.date_to)} · ${briefing.coverage.transaction_count} ${transactionLabel}`;
}

function absoluteAmount(amount: string): string {
    return amount.startsWith('-') ? amount.slice(1) : amount;
}

function PrimaryBriefing({ briefing }: { briefing: Briefing }) {
    const materialChange = briefing.material_change;
    const changedDown = materialChange?.change_minor.startsWith('-') ?? false;

    return (
        <div className="grid gap-6">
            <header className="grid gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge>{briefing.currency}</Badge>
                    <span className="text-sm font-medium">
                        {briefing.period.label}
                    </span>
                </div>
                <Link
                    href={periodBreakdownUrl({
                        currency: briefing.currency,
                        period: briefing.coverage,
                    })}
                    data-test="home-coverage"
                    className="w-fit rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                >
                    <span className="font-medium text-foreground">
                        Coverage
                    </span>{' '}
                    {coverageText(briefing)}
                </Link>
            </header>

            <section className="grid gap-3 md:grid-cols-3">
                {summaryItems.map((item, index) => {
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.label}
                            href={periodBreakdownUrl({
                                currency: briefing.currency,
                                period: briefing.period,
                                focus: item.focus,
                            })}
                            data-test={
                                index === 0 ? 'home-net-spending' : undefined
                            }
                            className={`group rounded-xl border p-4 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden ${index === 0 ? 'border-primary/30 bg-primary/5' : ''}`}
                        >
                            <span className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                                {item.label}
                                <Icon className="size-4" />
                            </span>
                            <span
                                className={`mt-3 block font-semibold tabular-nums ${index === 0 ? 'text-3xl' : 'text-2xl'}`}
                            >
                                {formatMinorUnits(
                                    briefing.summary[item.value],
                                    briefing.currency,
                                )}
                            </span>
                            <span className="mt-2 flex items-center gap-1 text-xs text-muted-foreground group-hover:text-foreground">
                                See supporting Transactions
                                <ArrowRight className="size-3" />
                            </span>
                        </Link>
                    );
                })}
            </section>

            {materialChange !== null && (
                <Card>
                    <CardHeader>
                        <CardDescription>One material change</CardDescription>
                        <CardTitle>{materialChange.category.name}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                        <div className="grid gap-1">
                            <p className="text-lg font-semibold">
                                {changedDown ? 'Down' : 'Up'}{' '}
                                {formatMinorUnits(
                                    absoluteAmount(materialChange.change_minor),
                                    briefing.currency,
                                )}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {formatMinorUnits(
                                    materialChange.current_total_minor,
                                    briefing.currency,
                                )}{' '}
                                this period, compared with a typical{' '}
                                {formatMinorUnits(
                                    materialChange.typical_total_minor,
                                    briefing.currency,
                                )}{' '}
                                across the prior three equivalent periods.
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span className="text-muted-foreground">
                                    Supporting periods
                                </span>
                                {materialChange.comparison_periods.map(
                                    (comparisonPeriod, index) => (
                                        <Link
                                            key={comparisonPeriod.date_from}
                                            href={categoryBreakdownUrl({
                                                currency: briefing.currency,
                                                period: comparisonPeriod,
                                                categoryId:
                                                    materialChange.category.id,
                                            })}
                                            data-test={`home-material-comparison-${index}`}
                                            className="rounded-md border px-2 py-1 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                        >
                                            {comparisonPeriod.label}
                                        </Link>
                                    ),
                                )}
                            </div>
                        </div>
                        <Button asChild variant="outline">
                            <Link
                                href={categoryBreakdownUrl({
                                    currency: briefing.currency,
                                    period: briefing.period,
                                    categoryId: materialChange.category.id,
                                })}
                                data-test="home-material-change"
                            >
                                See the change
                                <ArrowRight />
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            )}

            {briefing.input_request !== null && (
                <Link
                    href={periodBreakdownUrl({
                        currency: briefing.currency,
                        period: briefing.period,
                        attention: true,
                    })}
                    data-test="home-input-request"
                    className="flex flex-col gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 hover:bg-amber-500/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden sm:flex-row sm:items-center sm:justify-between"
                >
                    <span className="flex gap-3">
                        <CircleAlert className="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-400" />
                        <span>
                            <span className="block font-medium">
                                Needs your input
                            </span>
                            <span className="block text-sm text-muted-foreground">
                                {briefing.input_request.transaction_count}{' '}
                                {briefing.input_request.transaction_count === 1
                                    ? 'Transaction affects'
                                    : 'Transactions affect'}{' '}
                                how much you can trust this briefing.
                            </span>
                        </span>
                    </span>
                    <span className="flex items-center gap-1 text-sm font-medium">
                        Review evidence
                        <ArrowRight className="size-4" />
                    </span>
                </Link>
            )}
        </div>
    );
}

function SecondaryCurrency({ briefing }: { briefing: Briefing }) {
    const visibleItems = summaryItems.filter(
        (item) => briefing.summary[item.value] !== '0',
    );

    return (
        <Card>
            <CardHeader className="gap-2">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <CardDescription>
                            {briefing.period.label}
                        </CardDescription>
                        <CardTitle>{briefing.currency} summary</CardTitle>
                    </div>
                    <Badge variant="outline">{briefing.currency} only</Badge>
                </div>
                <p className="text-xs text-muted-foreground">
                    Coverage {coverageText(briefing)}
                </p>
            </CardHeader>
            <CardContent className="grid gap-4">
                {visibleItems.length > 0 && (
                    <dl className="grid gap-3 sm:grid-cols-3">
                        {visibleItems.map((item) => (
                            <div key={item.label} className="grid gap-1">
                                <dt className="text-xs text-muted-foreground">
                                    {item.label}
                                </dt>
                                <dd className="font-semibold tabular-nums">
                                    {formatMinorUnits(
                                        briefing.summary[item.value],
                                        briefing.currency,
                                    )}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={periodBreakdownUrl({
                                currency: briefing.currency,
                                period: briefing.period,
                            })}
                            data-test="home-usd-breakdown"
                        >
                            Open Breakdown
                        </Link>
                    </Button>
                    <Button asChild size="sm" variant="ghost">
                        <Link
                            href={trendsIndex({
                                query: { currency: briefing.currency },
                            })}
                        >
                            View Trends
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function Home({
    primary,
    secondary,
}: {
    primary: Briefing | null;
    secondary: Briefing | null;
}) {
    return (
        <>
            <Head title="Home" />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="grid gap-1">
                    <div className="flex items-center gap-2">
                        <ReceiptText className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Home
                        </h1>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        What matters in your money right now.
                    </p>
                </header>

                {primary === null ? (
                    <Card className="border-dashed">
                        <CardContent className="grid justify-items-center gap-3 p-8 text-center">
                            <CircleDollarSign className="size-8 text-muted-foreground" />
                            <div>
                                <p className="font-medium">
                                    No PEN briefing yet
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Add or import a Transaction to start your
                                    first meaningful period.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <PrimaryBriefing briefing={primary} />
                )}

                {secondary !== null && (
                    <SecondaryCurrency briefing={secondary} />
                )}
            </main>
        </>
    );
}

Home.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: home(),
        },
    ],
};
