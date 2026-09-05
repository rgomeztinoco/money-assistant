import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    CircleDollarSign,
    Landmark,
    PiggyBank,
    Tags,
    TrendingUp,
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

type FindingBase = {
    currency: Currency;
    current_total_minor: string;
    typical_total_minor: string;
    change_minor: string;
    current_transaction_count: number;
    typical_transaction_count: number;
    unusual_transaction: {
        id: number;
        description: string;
        amount_minor: string;
    } | null;
    scenario: { difference_minor: string } | null;
};

type CategoryFinding = FindingBase & {
    kind: 'category';
    category: { id: number | null; name: string };
};

type MerchantFinding = FindingBase & {
    kind: 'merchant';
    merchant: string;
};

type Finding = CategoryFinding | MerchantFinding;

type MonthlyContext = Period & {
    month: string;
    total_minor: string | null;
};

function absoluteAmount(amount: string): string {
    return amount.startsWith('-') ? amount.slice(1) : amount;
}

function findingName(finding: Finding): string {
    return finding.kind === 'category'
        ? finding.category.name
        : finding.merchant;
}

function findingTestId(finding: Finding): string {
    if (finding.kind === 'category') {
        return `trend-finding-category-${finding.category.id ?? 'uncategorized'}`;
    }

    const merchantKey = finding.merchant
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    return `trend-finding-merchant-${merchantKey}`;
}

function findingUrl(
    finding: Finding,
    period: Period,
    includeUnusualTransaction = true,
) {
    const selected = includeUnusualTransaction
        ? finding.unusual_transaction?.id
        : undefined;

    if (finding.kind === 'category') {
        return categoryBreakdownUrl({
            currency: finding.currency,
            period,
            categoryId: finding.category.id,
            selected,
        });
    }

    return periodBreakdownUrl({
        currency: finding.currency,
        period,
        merchant: finding.merchant,
        selected,
    });
}

function FindingCard({
    finding,
    period,
    comparisonPeriods,
}: {
    finding: Finding;
    period: Period;
    comparisonPeriods: Period[];
}) {
    const decreased = finding.change_minor.startsWith('-');

    return (
        <Card>
            <CardHeader className="gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Badge variant="outline">
                        {finding.kind === 'category' ? 'Category' : 'Merchant'}
                    </Badge>
                    <span className="text-sm font-semibold tabular-nums">
                        {decreased ? 'Down' : 'Up'}{' '}
                        {formatMinorUnits(
                            absoluteAmount(finding.change_minor),
                            finding.currency,
                        )}
                    </span>
                </div>
                <CardTitle>
                    <Link
                        href={findingUrl(finding, period)}
                        data-test={findingTestId(finding)}
                        className="inline-flex items-center gap-2 rounded-sm hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    >
                        {findingName(finding)}
                        <ArrowRight className="size-4" />
                    </Link>
                </CardTitle>
                <CardDescription>
                    {formatMinorUnits(
                        finding.current_total_minor,
                        finding.currency,
                    )}{' '}
                    this month to date, compared with a typical{' '}
                    {formatMinorUnits(
                        finding.typical_total_minor,
                        finding.currency,
                    )}{' '}
                    across the three equivalent periods.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="grid gap-1 rounded-lg bg-muted/40 p-3">
                    <p className="text-xs font-medium text-muted-foreground">
                        Frequency
                    </p>
                    <p className="text-sm">
                        {finding.current_transaction_count} this period, typical{' '}
                        {finding.typical_transaction_count}
                    </p>
                </div>

                {finding.unusual_transaction !== null && (
                    <div className="grid gap-1 rounded-lg border p-3">
                        <p className="text-xs font-medium text-muted-foreground">
                            Unusual Transaction
                        </p>
                        <p className="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span>
                                {finding.unusual_transaction.description}
                            </span>
                            <span className="font-semibold tabular-nums">
                                {formatMinorUnits(
                                    finding.unusual_transaction.amount_minor,
                                    finding.currency,
                                )}
                            </span>
                        </p>
                    </div>
                )}

                {finding.scenario !== null && (
                    <p className="rounded-lg border border-primary/20 bg-primary/5 p-3 text-sm">
                        If this matched the recent typical level, Net Spending
                        would be{' '}
                        {formatMinorUnits(
                            finding.scenario.difference_minor,
                            finding.currency,
                        )}{' '}
                        lower this period.
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-muted-foreground">
                        Supporting periods
                    </span>
                    {comparisonPeriods.map((comparisonPeriod, index) => (
                        <Link
                            key={comparisonPeriod.date_from}
                            href={findingUrl(finding, comparisonPeriod, false)}
                            data-test={`${findingTestId(finding)}-comparison-${index}`}
                            className="rounded-md border px-2 py-1 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {comparisonPeriod.label}
                        </Link>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function SummaryCards({
    summary,
    currency,
    period,
}: {
    summary: Summary;
    currency: Currency;
    period: Period;
}) {
    const items = [
        {
            label: 'Net Spending',
            amount: summary.net_spending_minor,
            focus: 'net_spending',
            icon: CircleDollarSign,
        },
        {
            label: 'Income',
            amount: summary.income_minor,
            focus: 'income',
            icon: Landmark,
        },
        {
            label: 'Moved to Savings',
            amount: summary.moved_to_savings_minor,
            focus: 'savings',
            icon: PiggyBank,
        },
    ] satisfies Array<{
        label: string;
        amount: string;
        focus: 'net_spending' | 'income' | 'savings';
        icon: typeof CircleDollarSign;
    }>;

    return (
        <section className="grid gap-3 md:grid-cols-3">
            {items.map((item) => {
                const Icon = item.icon;

                return (
                    <Link
                        key={item.label}
                        href={periodBreakdownUrl({
                            currency,
                            period,
                            focus: item.focus,
                        })}
                        className="rounded-xl border p-4 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    >
                        <span className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                            {item.label}
                            <Icon className="size-4" />
                        </span>
                        <span className="mt-2 block text-2xl font-semibold tabular-nums">
                            {formatMinorUnits(item.amount, currency)}
                        </span>
                    </Link>
                );
            })}
        </section>
    );
}

export default function Trends({
    currency,
    available_currencies: availableCurrencies,
    period,
    comparison_periods: comparisonPeriods,
    summary,
    findings,
    monthly_context: monthlyContext,
}: {
    currency: Currency;
    available_currencies: Currency[];
    period: Period;
    comparison_periods: Period[];
    summary: Summary | null;
    findings: Finding[];
    monthly_context: MonthlyContext[];
}) {
    return (
        <>
            <Head title="Trends" />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <TrendingUp className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Trends
                            </h1>
                        </div>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Equivalent days in the previous three months explain
                            what materially changed. Financial impact ranks
                            first, with frequency and unusual Transactions as
                            evidence.
                        </p>
                    </div>
                    {availableCurrencies.length > 0 && (
                        <div className="flex gap-2">
                            {availableCurrencies.map((availableCurrency) => (
                                <Button
                                    key={availableCurrency}
                                    asChild
                                    size="sm"
                                    variant={
                                        availableCurrency === currency
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    <Link
                                        href={trendsIndex({
                                            query: {
                                                currency: availableCurrency,
                                            },
                                        })}
                                        data-test={`trends-switch-${availableCurrency.toLowerCase()}`}
                                    >
                                        {availableCurrency}
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    )}
                </header>

                <section className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
                    <CalendarRange className="size-4 text-muted-foreground" />
                    <span className="font-medium">{period.label}</span>
                    <span className="text-muted-foreground">
                        compared automatically with
                    </span>
                    {comparisonPeriods.map((comparisonPeriod) => (
                        <Badge
                            key={comparisonPeriod.date_from}
                            variant="secondary"
                        >
                            {comparisonPeriod.label}
                        </Badge>
                    ))}
                </section>

                {summary === null ? (
                    <Card className="border-dashed">
                        <CardContent className="grid justify-items-center gap-2 p-8 text-center">
                            <TrendingUp className="size-8 text-muted-foreground" />
                            <p className="font-medium">
                                No {currency} activity this month to date
                            </p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Trends will appear after the current period has
                                recorded Transactions. No calendar zero is shown
                                as if it were a finding.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <SummaryCards
                            summary={summary}
                            currency={currency}
                            period={period}
                        />

                        <section className="grid gap-4">
                            <div className="flex items-center gap-2">
                                <Tags className="size-5 text-muted-foreground" />
                                <div>
                                    <h2 className="text-lg font-semibold">
                                        Material findings
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Category and merchant changes ranked by
                                        absolute {currency} impact.
                                    </p>
                                </div>
                            </div>
                            {findings.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                    No material Category or merchant change
                                    appears in this comparison.
                                </p>
                            ) : (
                                <div className="grid gap-4 xl:grid-cols-2">
                                    {findings.map((finding) => (
                                        <FindingCard
                                            key={`${finding.kind}-${findingName(finding)}`}
                                            finding={finding}
                                            period={period}
                                            comparisonPeriods={
                                                comparisonPeriods
                                            }
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    </>
                )}

                {monthlyContext.length > 0 && (
                    <section className="grid gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Six-month context
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Calendar months stay separate in {currency}. The
                                current month stops at today.
                            </p>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {monthlyContext.map((month) => (
                                <Link
                                    key={month.month}
                                    href={periodBreakdownUrl({
                                        currency,
                                        period: month,
                                    })}
                                    className="flex items-center justify-between gap-3 rounded-lg border p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                >
                                    <span className="text-sm font-medium">
                                        {month.label}
                                    </span>
                                    <span
                                        className={
                                            month.total_minor === null
                                                ? 'text-xs text-muted-foreground'
                                                : 'font-semibold tabular-nums'
                                        }
                                    >
                                        {month.total_minor === null
                                            ? 'No recorded activity'
                                            : formatMinorUnits(
                                                  month.total_minor,
                                                  currency,
                                              )}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
            </main>
        </>
    );
}

Trends.layout = {
    breadcrumbs: [
        {
            title: 'Trends',
            href: trendsIndex(),
        },
    ],
};
