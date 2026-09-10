import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    CircleDollarSign,
    TrendingUp,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { periodBreakdownUrl } from '@/lib/transaction-filter-url';
import { index as trendsIndex } from '@/routes/trends';
import type { Currency } from '@/types';
import { ChangeLedger } from './change-ledger';
import { MonthlyContextChart } from './monthly-context-chart';
import type { Period, Summary, TrendsProps } from './types';

function PeriodSummary({
    currency,
    period,
    summary,
}: {
    currency: Currency;
    period: Period;
    summary: Summary | null;
}) {
    return (
        <Card className="bg-muted/40 shadow-none">
            <CardContent className="grid gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        This period · {currency}
                    </p>
                    <CircleDollarSign className="size-4 text-muted-foreground" />
                </div>
                <div>
                    <p className="text-2xl font-semibold tabular-nums">
                        {summary === null
                            ? 'No activity'
                            : formatMinorUnits(
                                  summary.net_spending_minor,
                                  currency,
                              )}
                    </p>
                    <p className="text-sm">Net Spending</p>
                </div>
                <Separator />
                <p className="text-xs text-muted-foreground">
                    Spending minus refunds and reimbursements. Income and
                    Transfers stay separate.
                </p>
                <Button asChild size="sm" variant="outline">
                    <Link
                        href={periodBreakdownUrl({ currency, period })}
                        data-test="trends-period-breakdown"
                    >
                        Open in Breakdown
                        <ArrowRight data-icon="inline-end" />
                    </Link>
                </Button>
            </CardContent>
        </Card>
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
}: TrendsProps) {
    const currencyOptions = Array.from(
        new Set([currency, ...availableCurrencies]),
    );

    return (
        <>
            <Head title="Trends" />

            <main className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:gap-6 md:p-6">
                <header className="flex flex-col gap-4">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="grid gap-1">
                            <div className="flex items-center gap-2">
                                <TrendingUp className="size-5 text-muted-foreground" />
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    Trends
                                </h1>
                            </div>
                            <p className="max-w-2xl text-sm text-muted-foreground">
                                See where Net Spending changed, ranked by
                                financial impact. Expand a row before opening
                                its supporting Transactions in Breakdown.
                            </p>
                        </div>

                        <div
                            className="flex gap-1 self-start rounded-lg border p-1"
                            aria-label="Currency"
                        >
                            {currencyOptions.map((availableCurrency) => (
                                <Button
                                    key={availableCurrency}
                                    asChild
                                    size="sm"
                                    variant={
                                        availableCurrency === currency
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                >
                                    <Link
                                        href={trendsIndex({
                                            query: {
                                                currency: availableCurrency,
                                            },
                                        })}
                                        aria-current={
                                            availableCurrency === currency
                                                ? 'page'
                                                : undefined
                                        }
                                        data-test={`trends-switch-${availableCurrency.toLowerCase()}`}
                                    >
                                        {availableCurrency}
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    </div>

                    <section className="flex min-w-0 flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
                        <CalendarRange className="size-4 shrink-0 text-muted-foreground" />
                        <span className="font-medium">{period.label}</span>
                        <span className="text-muted-foreground">
                            compared with equivalent days in
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
                </header>

                <div className="grid gap-2">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        The change ledger
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Current and typical totals stay side by side while
                        evidence remains collapsed for scanning.
                    </p>
                </div>

                <div className="grid min-w-0 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_17rem]">
                    <ChangeLedger
                        currency={currency}
                        period={period}
                        comparisonPeriods={comparisonPeriods}
                        summary={summary}
                        findings={findings}
                    />

                    <aside className="grid min-w-0 content-start gap-5">
                        <PeriodSummary
                            currency={currency}
                            period={period}
                            summary={summary}
                        />
                        <MonthlyContextChart
                            currency={currency}
                            period={period}
                            months={monthlyContext}
                        />
                    </aside>
                </div>
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
