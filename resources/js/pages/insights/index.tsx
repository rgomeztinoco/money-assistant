import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CalendarDays,
    CircleDollarSign,
    Target,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import type { ReactNode } from 'react';
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
import { index as dailyExchangeRatesIndex } from '@/routes/daily_exchange_rates';
import { index as insightsIndex } from '@/routes/insights';
import { index as transactionsIndex } from '@/routes/transactions';
import type { CombinedTotal, Currency } from '@/types';

type CategoryTotal = {
    category: { id: number | null; name: string };
    totals: Record<Currency, string>;
    combined_total: CombinedTotal;
};

type SpendingSummary = {
    totals: Record<Currency, string>;
    combined_total: CombinedTotal;
    category_totals: CategoryTotal[];
};

type InsightPeriod = {
    month: string;
    label: string;
    date_from: string;
    date_to: string;
    is_complete: boolean;
    spending_label: 'Completed spending' | 'Spending to date';
    spending: SpendingSummary;
};

type BaselineMonth = {
    month: string;
    label: string;
    date_from: string;
    date_to: string;
    spending: SpendingSummary;
};

type ComparisonMetric = {
    current_amount_minor: string;
    baseline_average_minor: string;
    difference_amount_minor: string;
    difference_percentage_basis_points: string | null;
};

type CombinedComparison = {
    currency: Currency | null;
    current_amount_minor: string | null;
    baseline_average_minor: string | null;
    difference_amount_minor: string | null;
    difference_percentage_basis_points: string | null;
    unavailable_reason:
        'reporting_currency_not_selected' | 'missing_exchange_rates' | null;
    missing_rate_dates: string[];
};

type SpendingComparison = {
    baseline_months: string[];
    totals: Record<Currency, ComparisonMetric>;
    combined_total: CombinedComparison;
    category_totals: Array<{
        category: { id: number | null; name: string };
        totals: Record<Currency, ComparisonMetric>;
        combined_total: CombinedComparison;
    }>;
};

type InsightsIndexProps = {
    period: InsightPeriod;
    baseline: {
        status: 'unavailable' | 'provisional' | 'established';
        complete_month_count: number;
        months: BaselineMonth[];
        average: SpendingSummary | null;
    };
    comparison: SpendingComparison | null;
};

function formatPercentageBasisPoints(basisPoints: string): string {
    const isNegative = basisPoints.startsWith('-');
    const digits = (isNegative ? basisPoints.slice(1) : basisPoints).padStart(
        3,
        '0',
    );

    return `${isNegative ? '−' : '+'}${digits.slice(0, -2)}.${digits.slice(-2)}%`;
}

function Difference({
    metric,
    currency,
}: {
    metric: ComparisonMetric;
    currency: Currency;
}) {
    const isIncrease = !metric.difference_amount_minor.startsWith('-');
    const Icon = isIncrease ? TrendingUp : TrendingDown;

    return (
        <div className="flex items-start gap-2 text-sm">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <p>
                <span className="font-medium tabular-nums">
                    {formatMinorUnits(metric.difference_amount_minor, currency)}
                </span>{' '}
                {metric.difference_percentage_basis_points === null ? (
                    <span className="text-muted-foreground">
                        from a zero preceding average
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        (
                        {formatPercentageBasisPoints(
                            metric.difference_percentage_basis_points,
                        )}
                        )
                    </span>
                )}
            </p>
        </div>
    );
}

function CombinedUnavailable({
    combined,
    dateFrom,
    dateTo,
}: {
    combined: CombinedTotal | CombinedComparison;
    dateFrom: string;
    dateTo: string;
}) {
    const missingDate = combined.missing_rate_dates[0];

    return (
        <div className="grid gap-3 rounded-lg border border-dashed p-4">
            <p className="text-sm font-medium">Combined view unavailable</p>
            <p className="text-sm text-muted-foreground">
                {combined.unavailable_reason ===
                'reporting_currency_not_selected'
                    ? 'Choose a Reporting Currency. Original USD and PEN facts remain available.'
                    : 'Complete the affected Daily Exchange Rates. Original-currency facts remain available.'}
            </p>
            <Button asChild size="sm" variant="outline" className="w-fit">
                <Link
                    href={dailyExchangeRatesIndex({
                        query: {
                            date_from: dateFrom,
                            date_to: dateTo,
                            date: missingDate,
                        },
                    })}
                >
                    Review Daily Exchange Rates
                    <ArrowRight />
                </Link>
            </Button>
        </div>
    );
}

function CombinedDifference({
    combined,
    unavailable,
}: {
    combined: CombinedComparison;
    unavailable: ReactNode;
}) {
    if (
        combined.currency === null ||
        combined.current_amount_minor === null ||
        combined.baseline_average_minor === null ||
        combined.difference_amount_minor === null
    ) {
        return unavailable;
    }

    return (
        <Difference
            metric={{
                current_amount_minor: combined.current_amount_minor,
                baseline_average_minor: combined.baseline_average_minor,
                difference_amount_minor: combined.difference_amount_minor,
                difference_percentage_basis_points:
                    combined.difference_percentage_basis_points,
            }}
            currency={combined.currency}
        />
    );
}

export default function InsightsIndex({
    period,
    baseline,
    comparison,
}: InsightsIndexProps) {
    const combined = period.spending.combined_total;

    return (
        <>
            <Head title="Insights" />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <BarChart3 className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Spending Insights
                        </h1>
                        <Badge variant="outline">Descriptive only</Badge>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Factual spending history and comparisons with the
                        preceding three complete months. No month-end forecast
                        is produced.
                    </p>
                </div>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]">
                    <Card>
                        <CardHeader className="gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <CalendarDays className="size-5 text-muted-foreground" />
                                    <CardTitle>{period.label}</CardTitle>
                                </div>
                                <Badge
                                    variant={
                                        period.is_complete
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {period.spending_label}
                                </Badge>
                            </div>
                            <CardDescription>
                                Net purchases and Refunds recorded for this
                                calendar month.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-3">
                            {(['USD', 'PEN'] as const).map((currency) => (
                                <Link
                                    key={currency}
                                    href={transactionsIndex({
                                        query: {
                                            date_from: period.date_from,
                                            date_to: period.date_to,
                                            currency,
                                        },
                                    })}
                                    className="rounded-lg border bg-muted/20 p-4 transition-colors hover:bg-muted/50"
                                >
                                    <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {currency}
                                    </span>
                                    <span className="mt-2 block text-2xl font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            period.spending.totals[currency],
                                            currency,
                                        )}
                                    </span>
                                </Link>
                            ))}

                            {combined.currency !== null &&
                            combined.amount_minor !== null ? (
                                <Link
                                    href={transactionsIndex({
                                        query: {
                                            date_from: period.date_from,
                                            date_to: period.date_to,
                                        },
                                    })}
                                    className="rounded-lg border bg-primary p-4 text-primary-foreground transition-opacity hover:opacity-90"
                                >
                                    <span className="text-xs font-medium tracking-wide uppercase opacity-80">
                                        Combined in {combined.currency}
                                    </span>
                                    <span className="mt-2 block text-2xl font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            combined.amount_minor,
                                            combined.currency,
                                        )}
                                    </span>
                                </Link>
                            ) : (
                                <CombinedUnavailable
                                    combined={combined}
                                    dateFrom={period.date_from}
                                    dateTo={period.date_to}
                                />
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="gap-3">
                            <div className="flex items-center justify-between gap-2">
                                <CardTitle>Spending Baseline</CardTitle>
                                <Badge
                                    variant={
                                        baseline.status === 'established'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {baseline.status === 'established'
                                        ? 'Established'
                                        : baseline.status === 'provisional'
                                          ? 'Provisional'
                                          : 'Not available'}
                                </Badge>
                            </div>
                            <CardDescription>
                                {baseline.status === 'established'
                                    ? 'Arithmetic average of the preceding three complete calendar months.'
                                    : `${baseline.complete_month_count} of 3 complete months recorded. No comparison is made yet.`}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {baseline.months.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Complete reviewed months will appear here.
                                </p>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {baseline.months.map((month) => (
                                        <Button
                                            key={month.month}
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link
                                                href={insightsIndex({
                                                    query: {
                                                        date_from:
                                                            month.date_from,
                                                        date_to: month.date_to,
                                                    },
                                                })}
                                            >
                                                {month.label}
                                            </Link>
                                        </Button>
                                    ))}
                                </div>
                            )}

                            {baseline.average && (
                                <div className="grid gap-2 border-t pt-3 text-sm">
                                    <p className="font-medium">
                                        Preceding three-month average
                                    </p>
                                    <div className="flex flex-wrap gap-x-5 gap-y-1 text-muted-foreground">
                                        <span className="tabular-nums">
                                            {formatMinorUnits(
                                                baseline.average.totals.USD,
                                                'USD',
                                            )}{' '}
                                            USD
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMinorUnits(
                                                baseline.average.totals.PEN,
                                                'PEN',
                                            )}{' '}
                                            PEN
                                        </span>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </section>

                {comparison && (
                    <section className="grid gap-4">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-lg font-semibold">
                                Completed-month comparison
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {period.label} compared with the arithmetic
                                average of{' '}
                                {comparison.baseline_months.join(', ')}.
                            </p>
                        </div>

                        <div className="grid gap-3 md:grid-cols-3">
                            {(['USD', 'PEN'] as const).map((currency) => (
                                <Card key={currency} className="py-4">
                                    <CardHeader>
                                        <CardDescription>
                                            {currency} spending
                                        </CardDescription>
                                        <CardTitle className="text-2xl tabular-nums">
                                            {formatMinorUnits(
                                                comparison.totals[currency]
                                                    .current_amount_minor,
                                                currency,
                                            )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <Difference
                                            metric={comparison.totals[currency]}
                                            currency={currency}
                                        />
                                    </CardContent>
                                </Card>
                            ))}

                            <Card className="py-4">
                                <CardHeader>
                                    <CardDescription>
                                        Combined Reporting Currency
                                    </CardDescription>
                                    <CardTitle className="text-2xl tabular-nums">
                                        {comparison.combined_total.currency &&
                                        comparison.combined_total
                                            .current_amount_minor
                                            ? formatMinorUnits(
                                                  comparison.combined_total
                                                      .current_amount_minor,
                                                  comparison.combined_total
                                                      .currency,
                                              )
                                            : 'Unavailable'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <CombinedDifference
                                        combined={comparison.combined_total}
                                        unavailable={
                                            <CombinedUnavailable
                                                combined={
                                                    comparison.combined_total
                                                }
                                                dateFrom={period.date_from}
                                                dateTo={period.date_to}
                                            />
                                        }
                                    />
                                </CardContent>
                            </Card>
                        </div>

                        {comparison.category_totals.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Category comparisons</CardTitle>
                                    <CardDescription>
                                        Each Category is compared independently
                                        with its preceding three-month average.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3">
                                    {comparison.category_totals.map(
                                        (category) => (
                                            <div
                                                key={
                                                    category.category.id ??
                                                    'uncategorized'
                                                }
                                                className="grid gap-3 rounded-lg border p-4 lg:grid-cols-[minmax(10rem,0.8fr)_repeat(3,minmax(0,1fr))]"
                                            >
                                                <div>
                                                    <p className="font-medium">
                                                        {category.category.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Net monthly spending
                                                    </p>
                                                </div>
                                                {(['USD', 'PEN'] as const).map(
                                                    (currency) => (
                                                        <div
                                                            key={currency}
                                                            className="grid gap-1"
                                                        >
                                                            <span className="text-xs font-medium text-muted-foreground uppercase">
                                                                {currency}
                                                            </span>
                                                            <Difference
                                                                metric={
                                                                    category
                                                                        .totals[
                                                                        currency
                                                                    ]
                                                                }
                                                                currency={
                                                                    currency
                                                                }
                                                            />
                                                        </div>
                                                    ),
                                                )}
                                                <div className="grid gap-1">
                                                    <span className="text-xs font-medium text-muted-foreground uppercase">
                                                        Combined
                                                    </span>
                                                    <CombinedDifference
                                                        combined={
                                                            category.combined_total
                                                        }
                                                        unavailable={
                                                            <span className="text-sm text-muted-foreground">
                                                                Unavailable for
                                                                affected rates
                                                            </span>
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </section>
                )}

                <Card id="targets">
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Target className="size-5 text-muted-foreground" />
                            <CardTitle>Category Targets</CardTitle>
                        </div>
                        <CardDescription>
                            No active Category Targets yet. Targets are explicit
                            owner-approved intentions, never forecasts or
                            inferred reductions.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex items-start gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        <CircleDollarSign className="mt-0.5 size-4 shrink-0" />
                        {baseline.status === 'established'
                            ? 'The established Spending Baseline can provide context when you create a Category Target.'
                            : 'Three complete reviewed months are required before a Category Target can be created.'}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

InsightsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Insights',
            href: insightsIndex(),
        },
    ],
};
