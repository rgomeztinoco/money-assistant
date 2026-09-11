import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarRange } from 'lucide-react';
import { ReportingControls } from '@/components/reporting-controls';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { reportingQuery, reportingSelection } from '@/lib/reporting-query';
import { periodBreakdownUrl } from '@/lib/transaction-filter-url';
import { index as trendsIndex } from '@/routes/trends';
import type { Currency, ReportingPeriodSelection } from '@/types';
import { ChangeLedger } from './change-ledger';
import { MonthlyContextChart } from './monthly-context-chart';
import type {
    ComparisonPeriod,
    Period,
    Summary,
    TrendReport,
    TrendsProps,
} from './types';

function trendsReportingHref({
    currencyFilter,
    selection,
}: {
    currencyFilter: Currency | null;
    selection: ReportingPeriodSelection;
}): string {
    return trendsIndex.url({
        query: reportingQuery(currencyFilter, selection),
    });
}

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
        <Card className="gap-0 overflow-hidden py-0">
            <CardContent className="grid gap-4 p-4">
                <span className="text-xs font-semibold tracking-wider text-muted-foreground">
                    {currency}
                </span>
                <dl className="grid gap-1">
                    <dt className="text-sm text-muted-foreground">
                        Net spending
                    </dt>
                    <dd className="text-2xl font-semibold tracking-tight tabular-nums">
                        {summary === null
                            ? 'No activity'
                            : formatMinorUnits(
                                  summary.net_spending_minor,
                                  currency,
                              )}
                    </dd>
                </dl>
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

function ComparisonPeriodIndicator({
    comparisonPeriods,
}: {
    comparisonPeriods: ComparisonPeriod[];
}) {
    return (
        <div className="flex min-w-0 flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
            <CalendarRange className="size-4 shrink-0 text-muted-foreground" />
            <span className="text-muted-foreground">Compared with</span>
            {comparisonPeriods.map((comparisonPeriod) => (
                <Badge key={comparisonPeriod.date_from} variant="secondary">
                    {comparisonPeriod.label}
                </Badge>
            ))}
        </div>
    );
}

function TrendCurrencySection({
    report,
    period,
    comparisonPeriods,
}: {
    report: TrendReport;
    period: Period;
    comparisonPeriods: ComparisonPeriod[];
}) {
    return (
        <div className="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_17rem]">
            <ChangeLedger
                currency={report.currency}
                period={period}
                comparisonPeriods={comparisonPeriods}
                summary={report.summary}
                findings={report.findings}
            />

            <aside className="grid min-w-0 content-start gap-4">
                <PeriodSummary
                    currency={report.currency}
                    period={period}
                    summary={report.summary}
                />
                <MonthlyContextChart
                    currency={report.currency}
                    months={report.monthly_context}
                />
            </aside>
        </div>
    );
}

export default function Trends(props: TrendsProps) {
    return (
        <>
            <Head title="Trends" />

            <main className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <ComparisonPeriodIndicator
                    comparisonPeriods={props.comparison_periods}
                />

                <TrendCurrencySection
                    report={props}
                    period={props.period}
                    comparisonPeriods={props.comparison_periods}
                />

                {props.secondary !== null && (
                    <TrendCurrencySection
                        report={props.secondary}
                        period={props.period}
                        comparisonPeriods={props.comparison_periods}
                    />
                )}
            </main>
        </>
    );
}

Trends.layout = (props: TrendsProps) => ({
    breadcrumbs: [
        {
            title: 'Trends',
            href: trendsIndex({
                query: reportingQuery(
                    props.currency_filter,
                    reportingSelection(props.period),
                ),
            }),
        },
    ],
    headerActions: (
        <ReportingControls
            currencyFilter={props.currency_filter}
            period={props.period}
            today={props.today}
            href={(currencyFilter, selection) =>
                trendsReportingHref({
                    currencyFilter,
                    selection,
                })
            }
        />
    ),
});
