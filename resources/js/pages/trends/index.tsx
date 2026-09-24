import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CalendarRange } from 'lucide-react';
import { CurrencyFilter } from '@/components/currency-filter';
import { PeriodControls } from '@/components/period-controls';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    formatMonthSpan,
    formatReportingPeriod,
} from '@/lib/date-presentation';
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
        <section
            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 px-1 py-4 sm:px-4"
            data-test={`trends-summary-${currency.toLowerCase()}`}
        >
            <dl className="grid min-w-0 gap-1">
                <dt className="type-meta tracking-wider">{currency}</dt>
                <dd className="truncate text-2xl font-semibold tracking-tight tabular-nums">
                    {summary === null
                        ? 'No activity'
                        : formatMinorUnits(
                              summary.net_spending_minor,
                              currency,
                          )}
                </dd>
            </dl>
            <Button asChild size="icon" variant="ghost">
                <Link
                    href={periodBreakdownUrl({ currency, period })}
                    data-test={`trends-period-breakdown-${currency.toLowerCase()}`}
                    aria-label={`Open ${currency} Net Spending in Breakdown`}
                >
                    <ArrowRight />
                </Link>
            </Button>
        </section>
    );
}

function comparisonRange(comparisonPeriods: ComparisonPeriod[]): string {
    const oldest = comparisonPeriods.at(-1);
    const newest = comparisonPeriods[0];

    if (oldest === undefined || newest === undefined) {
        return '';
    }

    return formatMonthSpan(oldest.date_from, newest.date_to);
}

function ComparisonPeriodIndicator({
    comparisonPeriods,
    period,
}: {
    comparisonPeriods: ComparisonPeriod[];
    period: Period;
}) {
    const unit = period.unit === 'custom' ? 'periods' : `${period.unit}s`;

    return (
        <div className="flex min-w-0 flex-wrap items-center justify-end gap-2 type-body">
            <CalendarRange className="size-4 shrink-0 text-muted-foreground" />
            <span className="text-muted-foreground">Compared with</span>
            <span className="text-muted-foreground">
                Previous {comparisonPeriods.length} {unit}:{' '}
                <span className="font-medium text-foreground">
                    {comparisonRange(comparisonPeriods)}
                </span>
            </span>
        </div>
    );
}

export default function Trends(props: TrendsProps) {
    const reports: TrendReport[] =
        props.secondary === null ? [props] : [props, props.secondary];

    return (
        <>
            <Head title="Trends" />

            <main className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6 xl:overflow-hidden">
                <div
                    className="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    data-test="trends-context-bar"
                >
                    <CurrencyFilter
                        value={props.currency_filter}
                        options={[
                            {
                                value: null,
                                label: 'All',
                                testId: 'reporting-currency-all',
                            },
                            {
                                value: 'PEN',
                                label: 'PEN',
                                testId: 'reporting-currency-pen',
                            },
                            {
                                value: 'USD',
                                label: 'USD',
                                testId: 'reporting-currency-usd',
                            },
                        ]}
                        href={(currencyFilter) =>
                            trendsReportingHref({
                                currencyFilter,
                                selection: reportingSelection(props.period),
                            })
                        }
                    />
                    <ComparisonPeriodIndicator
                        comparisonPeriods={props.comparison_periods}
                        period={props.period}
                    />
                </div>

                <div className="grid min-h-0 min-w-0 flex-1 gap-4 xl:grid-cols-[minmax(20rem,0.8fr)_minmax(36rem,1.2fr)] xl:grid-rows-[minmax(0,1fr)] xl:items-stretch xl:overflow-hidden">
                    <aside
                        className="min-h-0 min-w-0 xl:h-full"
                        data-test="trends-overview-column"
                    >
                        <Card
                            className="min-h-0 min-w-0 gap-0 overflow-hidden py-0 xl:h-full"
                            data-test="trends-overview-card"
                        >
                            <CardContent className="flex h-full min-h-0 flex-col gap-6 p-4 sm:p-6">
                                <section
                                    className="grid shrink-0 gap-3"
                                    data-test="trends-net-spending"
                                >
                                    <div>
                                        <h2 className="type-section-title">
                                            Net spending
                                        </h2>
                                        <p className="type-subtitle">
                                            {formatReportingPeriod(
                                                props.period,
                                            )}
                                        </p>
                                    </div>
                                    <div
                                        className={`grid border-y ${
                                            reports.length > 1
                                                ? 'grid-cols-2 divide-x'
                                                : 'grid-cols-1'
                                        }`}
                                        data-test="trends-net-spending-currencies"
                                    >
                                        {reports.map((report) => (
                                            <PeriodSummary
                                                key={report.currency}
                                                currency={report.currency}
                                                period={props.period}
                                                summary={report.summary}
                                            />
                                        ))}
                                    </div>
                                </section>
                                <MonthlyContextChart reports={reports} />
                            </CardContent>
                        </Card>
                    </aside>

                    <ChangeLedger
                        currencyFilter={props.currency_filter}
                        period={props.period}
                        reports={reports}
                    />
                </div>
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
        <PeriodControls
            period={props.period}
            today={props.today}
            href={(selection) =>
                trendsReportingHref({
                    currencyFilter: props.currency_filter,
                    selection,
                })
            }
        />
    ),
    viewportConstrained: true,
});
