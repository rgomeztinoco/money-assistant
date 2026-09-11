import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { CurrencyFilter } from '@/components/currency-filter';
import { PeriodControls } from '@/components/period-controls';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { periodBreakdownUrl } from '@/lib/transaction-filter-url';
import { index as trendsIndex } from '@/routes/trends';
import type { Currency, ReportingPeriodSelection } from '@/types';
import { ChangeLedger } from './change-ledger';
import { MonthlyContextChart } from './monthly-context-chart';
import type { Period, Summary, TrendsProps } from './types';

function trendsPeriodHref({
    currency,
    selection,
}: {
    currency: Currency;
    selection: ReportingPeriodSelection;
}): string {
    return trendsIndex.url({
        query: {
            currency,
            period: selection.unit,
            anchor: selection.unit === 'custom' ? undefined : selection.anchor,
            date_from:
                selection.unit === 'custom' ? selection.dateFrom : undefined,
            date_to: selection.unit === 'custom' ? selection.dateTo : undefined,
        },
    });
}

function trendsCurrencyHref(currency: Currency, period: Period): string {
    return trendsIndex.url({
        query: {
            currency,
            period: period.unit,
            anchor: period.unit === 'custom' ? undefined : period.anchor,
            date_from: period.unit === 'custom' ? period.date_from : undefined,
            date_to: period.unit === 'custom' ? period.date_to : undefined,
        },
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

export default function Trends(props: TrendsProps) {
    return (
        <>
            <Head title="Trends" />

            <main className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <div
                    className="flex shrink-0 flex-wrap items-center gap-2"
                    data-test="trends-filter-bar"
                >
                    <CurrencyFilter
                        value={props.currency}
                        options={[
                            {
                                value: 'PEN',
                                label: 'PEN',
                                testId: 'trends-switch-pen',
                            },
                            {
                                value: 'USD',
                                label: 'USD',
                                testId: 'trends-switch-usd',
                            },
                        ]}
                        href={(currency) =>
                            trendsCurrencyHref(currency ?? 'PEN', props.period)
                        }
                    />
                </div>

                <div className="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_17rem]">
                    <ChangeLedger
                        currency={props.currency}
                        period={props.period}
                        comparisonPeriods={props.comparison_periods}
                        summary={props.summary}
                        findings={props.findings}
                    />

                    <aside className="grid min-w-0 content-start gap-4">
                        <PeriodSummary
                            currency={props.currency}
                            period={props.period}
                            summary={props.summary}
                        />
                        <MonthlyContextChart
                            currency={props.currency}
                            months={props.monthly_context}
                        />
                    </aside>
                </div>
            </main>
        </>
    );
}

Trends.layout = (props: TrendsProps) => ({
    breadcrumbs: [
        {
            title: 'Trends',
            href: trendsIndex(),
        },
    ],
    headerActions: (
        <PeriodControls
            period={props.period}
            today={props.today}
            href={(selection) =>
                trendsPeriodHref({
                    currency: props.currency,
                    selection,
                })
            }
        />
    ),
});
