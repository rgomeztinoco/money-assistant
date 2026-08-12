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
import { show as reportShow } from '@/routes/reports';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Currency } from '@/types';

type ReportPeriod = {
    label: string;
    date_from: string;
    date_to: string;
    total_minor: string;
};

type ReportMonth = {
    month: string;
    label: string;
    date_from: string;
    date_to: string;
    total_minor: string;
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
    period: ReportPeriod;
    monthly_history: ReportMonth[];
    category_groups: ReportCategoryGroup[];
};

function reportUrl(currency: Currency, period: ReportPeriod) {
    return reportShow(currency, {
        query: {
            date_from: period.date_from,
            date_to: period.date_to,
        },
    });
}

function transactionUrl(currency: Currency, dateFrom: string, dateTo: string) {
    return transactionsIndex({
        query: {
            currency,
            date_from: dateFrom,
            date_to: dateTo,
        },
    });
}

export default function ReportShow({
    currency,
    period,
    monthly_history: monthlyHistory,
    category_groups: categoryGroups,
}: ReportProps) {
    const otherCurrency: Currency = currency === 'PEN' ? 'USD' : 'PEN';

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
                            <Link href={reportUrl(currency, period)}>
                                {currency}
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link
                                href={reportUrl(otherCurrency, period)}
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
                                Net {currency} purchases after Refunds. Voided
                                Transactions are excluded.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <p
                                className="text-4xl font-semibold tabular-nums"
                                data-test="report-period-total"
                            >
                                {formatMinorUnits(period.total_minor, currency)}
                            </p>
                            <Button asChild variant="outline">
                                <Link
                                    href={transactionUrl(
                                        currency,
                                        period.date_from,
                                        period.date_to,
                                    )}
                                >
                                    View Transactions
                                    <ChevronRight />
                                </Link>
                            </Button>
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
                        <CardContent className="grid gap-2">
                            {monthlyHistory.map((month) => (
                                <Link
                                    key={month.month}
                                    href={reportShow(currency, {
                                        query: {
                                            date_from: month.date_from,
                                            date_to: month.date_to,
                                        },
                                    })}
                                    className="flex items-center justify-between gap-4 rounded-lg border bg-muted/20 p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                >
                                    <span className="font-medium">
                                        {month.label}
                                    </span>
                                    <span className="font-semibold tabular-nums">
                                        {formatMinorUnits(
                                            month.total_minor,
                                            currency,
                                        )}
                                    </span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <Tags className="size-5 text-muted-foreground" />
                            <CardTitle>Category groups</CardTitle>
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
                                        <div className="flex items-center justify-between gap-4">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <span className="truncate font-medium">
                                                    {group.category.name}
                                                </span>
                                                {group.category.archived && (
                                                    <Badge variant="outline">
                                                        Archived
                                                    </Badge>
                                                )}
                                            </div>
                                            <span className="font-semibold tabular-nums">
                                                {formatMinorUnits(
                                                    group.amount_minor,
                                                    currency,
                                                )}
                                            </span>
                                        </div>

                                        {group.children.length > 0 && (
                                            <div className="grid gap-2 border-t pt-2">
                                                {group.children.map((child) => (
                                                    <div
                                                        key={child.category.id}
                                                        className="flex items-center justify-between gap-4 pl-4 text-sm"
                                                    >
                                                        <div className="flex min-w-0 items-center gap-2">
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
                                                        </div>
                                                        <span className="tabular-nums">
                                                            {formatMinorUnits(
                                                                child.amount_minor,
                                                                currency,
                                                            )}
                                                        </span>
                                                    </div>
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
