import { Form, Head, Link } from '@inertiajs/react';
import { BarChart3, CalendarDays, ChevronRight, Tags } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { spendingComparisonDescription } from '@/lib/spending-comparison';
import type { SpendingComparison } from '@/lib/spending-comparison';
import {
    categoryTransactionsUrl,
    periodTransactionsUrl,
} from '@/lib/transaction-filter-url';
import { show as reportShow } from '@/routes/reports';
import type { Currency } from '@/types';

type ReportPeriod = {
    label: string;
    date_from: string;
    date_to: string;
    total_minor: string;
};

type PeriodSummary = {
    net_spending_minor: string;
    income_minor: string;
    moved_to_savings_minor: string;
};

type ReportComparison = SpendingComparison & {
    period: Omit<ReportPeriod, 'total_minor'>;
};

type ReportMonth = {
    month: string;
    label: string;
    date_from: string;
    date_to: string;
    total_minor: string;
    transaction_count: number;
};

type ReportCategoryAmount = {
    category: {
        id: number | null;
        name: string;
        archived: boolean;
    };
    amount_minor: string;
};

type ReportCategoryGroup = ReportCategoryAmount & {
    children: ReportCategoryAmount[];
};

type ReportProps = {
    currency: Currency;
    summary: PeriodSummary;
    period: ReportPeriod;
    comparison: ReportComparison;
    monthly_history: ReportMonth[];
    category_groups: ReportCategoryGroup[];
};

function reportUrl({
    currency,
    period,
}: {
    currency: Currency;
    period: ReportPeriod;
}) {
    return reportShow(currency, {
        query: {
            date_from: period.date_from,
            date_to: period.date_to,
        },
    });
}

function absoluteMinorUnits(value: string) {
    const amount = BigInt(value);

    return amount < 0n ? -amount : amount;
}

function chartWidth({ value, values }: { value: string; values: string[] }) {
    const maximum = values.reduce((largest, candidate) => {
        const amount = absoluteMinorUnits(candidate);

        return amount > largest ? amount : largest;
    }, 0n);

    if (maximum === 0n) {
        return 0;
    }

    const hundredthsOfAPercent =
        (absoluteMinorUnits(value) * 10_000n) / maximum;

    if (hundredthsOfAPercent === 0n && absoluteMinorUnits(value) > 0n) {
        return 0.5;
    }

    return Number(hundredthsOfAPercent) / 100;
}

function ChartBar({ value, values }: { value: string; values: string[] }) {
    return (
        <span
            className="h-2 overflow-hidden rounded-full bg-muted"
            aria-hidden="true"
        >
            <span
                data-test="chart-bar"
                className={`block h-full rounded-full ${value.startsWith('-') ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-primary'}`}
                style={{
                    width: `${chartWidth({ value, values })}%`,
                }}
            />
        </span>
    );
}

export default function ReportShow({
    currency,
    summary,
    period,
    comparison,
    monthly_history: monthlyHistory,
    category_groups: categoryGroups,
}: ReportProps) {
    const otherCurrency: Currency = currency === 'PEN' ? 'USD' : 'PEN';
    const monthlyAmounts = monthlyHistory.map((month) => month.total_minor);
    const categoryAmounts = categoryGroups.flatMap((group) => [
        group.amount_minor,
        ...group.children.map((child) => child.amount_minor),
    ]);
    const hasMonthlyActivity = monthlyHistory.some(
        (month) => month.transaction_count > 0,
    );
    const recordedMonthCount = monthlyHistory.filter(
        (month) => month.transaction_count > 0,
    ).length;

    return (
        <>
            <Head title={`${currency} Report`} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <BarChart3 className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {currency} spending report
                            </h1>
                            <Badge variant="outline">{currency} only</Badge>
                        </div>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Purchases, Refunds, monthly history, and Category
                            totals are calculated only in {currency}.
                        </p>
                    </div>

                    <div className="flex gap-2" aria-label="Report currency">
                        <Button asChild variant="secondary">
                            <Link href={reportUrl({ currency, period })}>
                                {currency}
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link
                                href={reportUrl({
                                    currency: otherCurrency,
                                    period,
                                })}
                                data-test={`report-switch-${otherCurrency.toLowerCase()}`}
                            >
                                {otherCurrency}
                            </Link>
                        </Button>
                    </div>
                </div>

                <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.7fr)]">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <CalendarDays className="size-5 text-muted-foreground" />
                                <Badge variant="secondary">
                                    Selected period
                                </Badge>
                            </div>
                            <CardTitle>{period.label}</CardTitle>
                            <CardDescription>
                                Confirmed {currency} movement summaries. Voided
                                Transactions are excluded.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <dl className="grid gap-3 sm:grid-cols-3">
                                <div className="grid gap-1 rounded-lg border p-3 sm:col-span-3">
                                    <dt className="text-sm text-muted-foreground">
                                        Net Spending
                                    </dt>
                                    <dd
                                        className="text-4xl font-semibold tabular-nums"
                                        data-test="report-period-total"
                                    >
                                        {formatMinorUnits(
                                            summary.net_spending_minor,
                                            currency,
                                        )}
                                    </dd>
                                </div>
                                <div className="grid gap-1 rounded-lg border p-3">
                                    <dt className="text-sm text-muted-foreground">
                                        Income
                                    </dt>
                                    <dd className="text-lg font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            summary.income_minor,
                                            currency,
                                        )}
                                    </dd>
                                </div>
                                <div className="grid gap-1 rounded-lg border p-3 sm:col-span-2">
                                    <dt className="text-sm text-muted-foreground">
                                        Moved to Savings
                                    </dt>
                                    <dd className="text-lg font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            summary.moved_to_savings_minor,
                                            currency,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                                <div className="grid gap-1">
                                    <p className="text-sm font-medium">
                                        {spendingComparisonDescription({
                                            comparison,
                                            currency,
                                        })}
                                    </p>
                                </div>
                                <Button asChild variant="outline">
                                    <Link
                                        href={periodTransactionsUrl({
                                            currency,
                                            period,
                                        })}
                                    >
                                        View Transactions
                                        <ChevronRight />
                                    </Link>
                                </Button>
                            </div>
                            <Link
                                href={periodTransactionsUrl({
                                    currency,
                                    period: comparison.period,
                                })}
                                className="flex flex-col gap-1 rounded-lg border bg-muted/20 p-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden sm:flex-row sm:items-center sm:justify-between"
                            >
                                <span className="text-sm text-muted-foreground">
                                    Previous equivalent period,{' '}
                                    {comparison.period.label}
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {formatMinorUnits(
                                        comparison.previous_total_minor,
                                        currency,
                                    )}
                                </span>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Choose a period</CardTitle>
                            <CardDescription>
                                Select any recorded date range through today.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action={reportShow.url(currency)}
                                method="get"
                                className="grid gap-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="date_from">
                                                    From
                                                </Label>
                                                <Input
                                                    id="date_from"
                                                    name="date_from"
                                                    type="date"
                                                    defaultValue={
                                                        period.date_from
                                                    }
                                                    aria-invalid={
                                                        errors.date_from
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                                <InputError
                                                    message={errors.date_from}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="date_to">
                                                    Through
                                                </Label>
                                                <Input
                                                    id="date_to"
                                                    name="date_to"
                                                    type="date"
                                                    defaultValue={
                                                        period.date_to
                                                    }
                                                    aria-invalid={
                                                        errors.date_to
                                                            ? true
                                                            : undefined
                                                    }
                                                />
                                                <InputError
                                                    message={errors.date_to}
                                                />
                                            </div>
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Update report
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </section>

                <section className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CalendarDays className="size-5 text-muted-foreground" />
                            <CardTitle>Monthly history</CardTitle>
                            <CardDescription>
                                Calendar-month {currency} totals through the
                                selected period end.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {!hasMonthlyActivity && (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    Monthly patterns will appear as Transactions
                                    accumulate. This period has no recorded{' '}
                                    {currency} spending yet.
                                </p>
                            )}
                            {recordedMonthCount === 1 && (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    One month has recorded spending.
                                    Month-over-month patterns will appear after
                                    another month of Transactions accumulates.
                                </p>
                            )}
                            {monthlyHistory.map((month) => (
                                <Link
                                    key={month.month}
                                    href={periodTransactionsUrl({
                                        currency,
                                        period: month,
                                    })}
                                    data-test={`report-month-${month.month}`}
                                    className="grid gap-2 rounded-lg border bg-muted/20 p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                >
                                    <span className="flex items-center justify-between gap-4">
                                        <span className="font-medium">
                                            {month.label}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {formatMinorUnits(
                                                month.total_minor,
                                                currency,
                                            )}
                                        </span>
                                    </span>
                                    <ChartBar
                                        value={month.total_minor}
                                        values={monthlyAmounts}
                                    />
                                </Link>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <Tags className="size-5 text-muted-foreground" />
                            <CardTitle>Category composition</CardTitle>
                            <CardDescription>
                                Parent totals include their child Categories
                                once. Receipt Breakdown Line Items replace the
                                Transaction Category.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {categoryGroups.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No {currency} spending was recorded in this
                                    period.
                                </p>
                            ) : (
                                categoryGroups.map((group) => (
                                    <div
                                        key={
                                            group.category.id ?? 'uncategorized'
                                        }
                                        className="grid gap-2 rounded-lg border p-3"
                                    >
                                        <Link
                                            href={categoryTransactionsUrl({
                                                currency,
                                                period,
                                                categoryId: group.category.id,
                                            })}
                                            data-test={`report-category-${group.category.id ?? 'uncategorized'}`}
                                            className="grid gap-2 rounded-md p-1 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                        >
                                            <span className="flex items-center justify-between gap-4">
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <span className="truncate font-medium">
                                                        {group.category.name}
                                                    </span>
                                                    {group.category
                                                        .archived && (
                                                        <Badge variant="outline">
                                                            Archived
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span className="font-semibold tabular-nums">
                                                    {formatMinorUnits(
                                                        group.amount_minor,
                                                        currency,
                                                    )}
                                                </span>
                                            </span>
                                            <ChartBar
                                                value={group.amount_minor}
                                                values={categoryAmounts}
                                            />
                                        </Link>

                                        {group.children.length > 0 && (
                                            <div className="grid gap-2 border-t pt-2">
                                                {group.children.map((child) => (
                                                    <Link
                                                        key={child.category.id}
                                                        href={categoryTransactionsUrl(
                                                            {
                                                                currency,
                                                                period,
                                                                categoryId:
                                                                    child
                                                                        .category
                                                                        .id,
                                                            },
                                                        )}
                                                        data-test={`report-category-${child.category.id}`}
                                                        className="grid gap-2 rounded-md p-2 pl-4 text-sm hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                                    >
                                                        <span className="flex items-center justify-between gap-4">
                                                            <span className="flex min-w-0 items-center gap-2">
                                                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                                                <span className="truncate">
                                                                    {
                                                                        child
                                                                            .category
                                                                            .name
                                                                    }
                                                                </span>
                                                                {child.category
                                                                    .archived && (
                                                                    <Badge variant="outline">
                                                                        Archived
                                                                    </Badge>
                                                                )}
                                                            </span>
                                                            <span className="tabular-nums">
                                                                {formatMinorUnits(
                                                                    child.amount_minor,
                                                                    currency,
                                                                )}
                                                            </span>
                                                        </span>
                                                        <ChartBar
                                                            value={
                                                                child.amount_minor
                                                            }
                                                            values={
                                                                categoryAmounts
                                                            }
                                                        />
                                                    </Link>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </section>
            </div>
        </>
    );
}

ReportShow.layout = {
    breadcrumbs: [
        {
            title: 'Reports',
            href: reportShow('PEN'),
        },
    ],
};
