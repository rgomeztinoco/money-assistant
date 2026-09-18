import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    CircleDollarSign,
    Landmark,
    PiggyBank,
} from 'lucide-react';
import { useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import { CurrencyFilter } from '@/components/currency-filter';
import { PeriodControls } from '@/components/period-controls';
import { SourceCoverage } from '@/components/source-coverage';
import type { RecordedCoverageSource } from '@/components/source-coverage';
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
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { reportingQuery, reportingSelection } from '@/lib/reporting-query';
import {
    categoryBreakdownUrl,
    periodBreakdownUrl,
} from '@/lib/transaction-filter-url';
import { home } from '@/routes';
import { create as createStatementImport } from '@/routes/statement_imports';
import type {
    Currency,
    ReportingPeriod,
    ReportingPeriodSelection,
} from '@/types';

type Period = ReportingPeriod;

type Summary = {
    net_spending_minor: string;
    income_minor: string;
    moved_to_savings_minor: string;
};

type PulseEvidence = {
    id: number;
    description: string;
    occurred_on: string;
    amount_minor: string;
    period: 'current' | 'previous';
};

type PulseSignal = {
    category: { id: number | null; name: string };
    current_total_minor: string;
    previous_total_minor: string;
    change_minor: string;
    current_transaction_count: number;
    previous_transaction_count: number;
    evidence: PulseEvidence[];
};

type Pulse = {
    previous_period: Pick<Period, 'label' | 'date_from' | 'date_to'>;
    previous_net_spending_minor: string;
    change_minor: string;
    percentage_change: number | null;
    daily_net_spending: {
        day: number;
        current_minor: string | null;
        previous_minor: string | null;
    }[];
    signals: PulseSignal[];
};

type Briefing = {
    currency: Currency;
    period: Period;
    coverage: {
        date_from: string;
        date_to: string;
        transaction_count: number;
        source: RecordedCoverageSource;
    };
    summary: Summary;
    pulse: Pulse | null;
    input_request: { transaction_count: number } | null;
};

type HomeProps = {
    currency_filter: Currency | null;
    period: Period;
    primary: Briefing | null;
    secondary: Briefing | null;
    today: string;
};

type SummaryItem = {
    label: string;
    value: keyof Summary;
    focus: 'net_spending' | 'income' | 'savings';
    note: string;
    icon: typeof CircleDollarSign;
};

const summaryItems: SummaryItem[] = [
    {
        label: 'Net spending',
        value: 'net_spending_minor',
        focus: 'net_spending',
        note: 'Spending less refunds',
        icon: CircleDollarSign,
    },
    {
        label: 'Income',
        value: 'income_minor',
        focus: 'income',
        note: 'Recorded this period',
        icon: Landmark,
    },
    {
        label: 'Moved to savings',
        value: 'moved_to_savings_minor',
        focus: 'savings',
        note: 'Transfers less withdrawals',
        icon: PiggyBank,
    },
];

function homeReportingHref({
    currencyFilter,
    selection,
}: {
    currencyFilter: Currency | null;
    selection: ReportingPeriodSelection;
}): string {
    return home.url({ query: reportingQuery(currencyFilter, selection) });
}

function shortDate(date: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}

function dayNumber(dateFrom: string, date: string): number {
    const millisecondsPerDay = 24 * 60 * 60 * 1000;

    return (
        Math.round(
            (Date.parse(`${date}T00:00:00Z`) -
                Date.parse(`${dateFrom}T00:00:00Z`)) /
                millisecondsPerDay,
        ) + 1
    );
}

function coverageText(briefing: Briefing): string {
    if (briefing.coverage.transaction_count === 0) {
        return `${shortDate(briefing.coverage.date_from)} to ${shortDate(briefing.coverage.date_to)}`;
    }

    const transactionLabel =
        briefing.coverage.transaction_count === 1
            ? 'Transaction'
            : 'Transactions';

    return `${shortDate(briefing.coverage.date_from)} to ${shortDate(briefing.coverage.date_to)} · ${briefing.coverage.transaction_count} ${transactionLabel}`;
}

function absoluteAmount(amount: string): string {
    return amount.startsWith('-') ? amount.slice(1) : amount;
}

function periodReference(period: Period): string {
    return period.unit === 'month' ? 'this month' : 'this period';
}

function transactionCount(count: number): string {
    return `${count} ${count === 1 ? 'Transaction' : 'Transactions'}`;
}

function spendingChangeDescription(briefing: Briefing): string {
    const pulse = briefing.pulse;

    if (pulse === null || pulse.change_minor === '0') {
        return `unchanged from ${pulse?.previous_period.label ?? 'the previous period'}`;
    }

    const changedDown = pulse.change_minor.startsWith('-');

    return `${formatMinorUnits(absoluteAmount(pulse.change_minor), briefing.currency)} ${changedDown ? 'lower' : 'higher'} than ${pulse.previous_period.label}`;
}

function percentageChangeLabel(briefing: Briefing): string {
    const percentageChange = briefing.pulse?.percentage_change;

    if (percentageChange === null || percentageChange === undefined) {
        return 'No prior baseline';
    }

    return `${percentageChange > 0 ? '+' : ''}${percentageChange}%`;
}

function SpendingComparisonChart({
    briefings,
    today,
}: {
    briefings: Briefing[];
    today: string;
}) {
    const primary = briefings[0];
    const primaryPulse = primary?.pulse;

    if (primary === undefined || primaryPulse === null) {
        return null;
    }

    const series = briefings.flatMap((briefing) => [
        {
            key: `${briefing.currency}_current`,
            label: `${briefing.currency} · Current`,
            currency: briefing.currency,
            previous: false,
        },
        {
            key: `${briefing.currency}_previous`,
            label: `${briefing.currency} · Previous`,
            currency: briefing.currency,
            previous: true,
        },
    ]);
    const chartConfig = Object.fromEntries(
        series.map((item) => [
            item.key,
            {
                label: item.label,
                color:
                    item.currency === 'PEN'
                        ? 'var(--chart-1)'
                        : 'var(--chart-2)',
            },
        ]),
    ) satisfies ChartConfig;
    const chartData = primaryPulse.daily_net_spending.map((primaryPoint) => {
        const point: Record<string, number | null> = {
            day: primaryPoint.day,
        };

        briefings.forEach((briefing) => {
            const briefingPoint = briefing.pulse?.daily_net_spending.find(
                (candidate) => candidate.day === primaryPoint.day,
            );
            point[`${briefing.currency}_current`] =
                briefingPoint?.current_minor == null
                    ? null
                    : Number(briefingPoint.current_minor) / 100;
            point[`${briefing.currency}_previous`] =
                briefingPoint?.previous_minor == null
                    ? null
                    : Number(briefingPoint.previous_minor) / 100;
        });

        return point;
    });
    const hasFutureDates =
        primary.period.date_from <= today && today < primary.period.date_to;
    const todayLabel = shortDate(today);
    const chartDescription = `Cumulative Net Spending in ${primary.period.label} compared with ${primaryPulse.previous_period.label} for ${briefings.map((briefing) => briefing.currency).join(' and ')}`;

    return (
        <ChartContainer
            config={chartConfig}
            className="h-64 w-full max-w-full min-w-0 md:h-72"
            role="img"
            aria-label={
                hasFutureDates
                    ? `${chartDescription}. Observed through ${todayLabel}; future dates have no values.`
                    : chartDescription
            }
            data-test="home-spending-chart"
        >
            <LineChart
                accessibilityLayer
                data={chartData}
                margin={{ left: -12, right: 12, top: 12, bottom: 0 }}
            >
                <CartesianGrid vertical={false} />
                <ReferenceLine y={0} stroke="var(--border)" />
                {hasFutureDates && (
                    <ReferenceLine
                        x={dayNumber(primary.period.date_from, today)}
                        stroke="var(--muted-foreground)"
                        strokeDasharray="3 4"
                        strokeOpacity={0.55}
                        label={{
                            value: 'Today',
                            position: 'insideTopRight',
                            fill: 'var(--muted-foreground)',
                            fontSize: 11,
                        }}
                        shape={(line) => (
                            <line
                                {...line}
                                className="recharts-reference-line-line"
                                aria-label={`Today, ${todayLabel}. Observed data ends here.`}
                                data-test="home-today-marker"
                            />
                        )}
                    />
                )}
                <XAxis
                    dataKey="day"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    interval="preserveStartEnd"
                    minTickGap={24}
                    tickFormatter={(day: number) =>
                        day === 0 ? '' : `Day ${day}`
                    }
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    width={56}
                    tickFormatter={(value: number) =>
                        new Intl.NumberFormat('en', {
                            notation: 'compact',
                            maximumFractionDigits: 1,
                        }).format(value)
                    }
                />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            config={chartConfig}
                            formatLabel={(day) => `Day ${day}`}
                            formatValue={(value, name) =>
                                formatMinorUnits(
                                    String(Math.round(Number(value) * 100)),
                                    String(name).startsWith('USD')
                                        ? 'USD'
                                        : 'PEN',
                                )
                            }
                        />
                    }
                />
                <ChartLegend
                    content={
                        <ChartLegendContent
                            config={chartConfig}
                            className="flex-wrap"
                        />
                    }
                />
                {series.map((item) => (
                    <Line
                        key={item.key}
                        dataKey={item.key}
                        type="monotone"
                        stroke={`var(--color-${item.key})`}
                        strokeDasharray={item.previous ? '4 5' : undefined}
                        strokeOpacity={item.previous ? 0.65 : 1}
                        strokeWidth={item.previous ? 1.5 : 2.5}
                        dot={false}
                        isAnimationActive={false}
                        connectNulls={false}
                    />
                ))}
            </LineChart>
        </ChartContainer>
    );
}

function SpendingSnapshot({ briefings }: { briefings: Briefing[] }) {
    return (
        <dl className="grid grid-cols-1 divide-y border-y sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {summaryItems.map((item, index) => {
                const Icon = item.icon;

                return (
                    <div
                        key={item.label}
                        className="grid gap-1 px-4 py-4 first:pl-0 last:pr-0"
                    >
                        <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Icon className="size-3.5" />
                            {item.label}
                        </dt>
                        <dd className="flex flex-wrap items-baseline gap-x-1 text-xl font-semibold tracking-tight tabular-nums">
                            {briefings.map((briefing, briefingIndex) => (
                                <span key={briefing.currency}>
                                    {briefingIndex > 0 && (
                                        <span className="mr-1 text-muted-foreground">
                                            +
                                        </span>
                                    )}
                                    <Link
                                        href={periodBreakdownUrl({
                                            currency: briefing.currency,
                                            period: briefing.period,
                                            focus: item.focus,
                                        })}
                                        data-test={
                                            index === 0 && briefingIndex === 0
                                                ? 'home-net-spending'
                                                : `home-${briefing.currency.toLowerCase()}-${item.focus.replaceAll('_', '-')}`
                                        }
                                        className="rounded-sm hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                    >
                                        {formatMinorUnits(
                                            briefing.summary[item.value],
                                            briefing.currency,
                                        )}
                                    </Link>
                                </span>
                            ))}
                        </dd>
                        <dd className="text-xs text-muted-foreground">
                            {item.note}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

function ReviewPrompt({ briefing }: { briefing: Briefing }) {
    if (briefing.input_request === null) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
            <p className="text-xs text-muted-foreground">
                {transactionCount(briefing.input_request.transaction_count)}{' '}
                {briefing.input_request.transaction_count === 1
                    ? 'needs'
                    : 'need'}{' '}
                review.
            </p>
            <Button asChild variant="link" size="sm" className="px-0">
                <Link
                    href={periodBreakdownUrl({
                        currency: briefing.currency,
                        period: briefing.period,
                        attention: true,
                    })}
                    data-test={`home-${briefing.currency.toLowerCase()}-input-request`}
                >
                    <span>Review Transactions</span>
                    <ArrowRight data-icon="inline-end" />
                </Link>
            </Button>
        </div>
    );
}

function SignalEvidence({
    briefing,
    pulse,
    signal,
}: {
    briefing: Briefing;
    pulse: Pulse;
    signal: PulseSignal;
}) {
    const testSegment = briefing.currency.toLowerCase();

    return (
        <div
            className="grid min-w-0 content-start gap-3 border-t pt-5"
            aria-live="polite"
            data-test={`home-${testSegment}-signal-evidence`}
        >
            <div className="grid gap-1">
                <h3 className="font-medium">{signal.category.name}</h3>
                <p className="text-xs leading-relaxed break-words text-muted-foreground">
                    {transactionCount(signal.current_transaction_count)}{' '}
                    contributed{' '}
                    {formatMinorUnits(
                        signal.current_total_minor,
                        briefing.currency,
                    )}{' '}
                    {periodReference(briefing.period)}, compared with{' '}
                    {transactionCount(signal.previous_transaction_count)} and{' '}
                    {formatMinorUnits(
                        signal.previous_total_minor,
                        briefing.currency,
                    )}{' '}
                    in {pulse.previous_period.label}.
                </p>
            </div>

            {signal.evidence.length > 0 ? (
                <dl className="grid gap-2">
                    {signal.evidence.map((item) => (
                        <div
                            key={item.id}
                            className="flex min-w-0 items-start justify-between gap-3 text-xs"
                        >
                            <div className="min-w-0">
                                <dt className="truncate">{item.description}</dt>
                                <dd className="text-muted-foreground">
                                    {shortDate(item.occurred_on)} ·{' '}
                                    {item.period === 'current'
                                        ? briefing.period.label
                                        : pulse.previous_period.label}
                                </dd>
                            </div>
                            <dd className="shrink-0 tabular-nums">
                                {formatMinorUnits(
                                    item.amount_minor,
                                    briefing.currency,
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="text-xs text-muted-foreground">
                    No Transactions in this category during the current period.
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline">
                    <Link
                        href={categoryBreakdownUrl({
                            currency: briefing.currency,
                            period: briefing.period,
                            categoryId: signal.category.id,
                        })}
                        data-test={`home-${testSegment}-material-change`}
                    >
                        Open current period
                        <ArrowRight data-icon="inline-end" />
                    </Link>
                </Button>
                <Button asChild size="sm" variant="ghost">
                    <Link
                        href={categoryBreakdownUrl({
                            currency: briefing.currency,
                            period: pulse.previous_period,
                            categoryId: signal.category.id,
                        })}
                        data-test={`home-${testSegment}-material-comparison`}
                    >
                        Open previous period
                    </Link>
                </Button>
            </div>

            <ReviewPrompt briefing={briefing} />
        </div>
    );
}

function CurrencySignalLane({ briefing }: { briefing: Briefing }) {
    const pulse = briefing.pulse;
    const [selectedIndex, setSelectedIndex] = useState(0);
    const signal = pulse?.signals[selectedIndex] ?? pulse?.signals[0];
    const testSegment = briefing.currency.toLowerCase();

    if (pulse === null) {
        return null;
    }

    return (
        <section
            className="grid min-w-0 content-start gap-5 p-4 sm:p-6"
            data-test={`home-${testSegment}-signals`}
        >
            <div
                className="grid min-w-0 content-start gap-3"
                data-test={`home-${testSegment}-signal-list`}
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="grid gap-1">
                        <h3 className="font-semibold">{briefing.currency}</h3>
                        <p className="text-xs text-muted-foreground">
                            Net Spending is{' '}
                            {spendingChangeDescription(briefing)}.
                        </p>
                    </div>
                    <Badge variant="secondary">
                        {pulse.signals.length}{' '}
                        {pulse.signals.length === 1 ? 'signal' : 'signals'}
                    </Badge>
                </div>

                {signal === undefined ? (
                    <div className="grid gap-3 border-t pt-4">
                        <p className="text-sm font-medium">
                            No category change yet
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Net Spending has no category movement to explain for
                            this comparison.
                        </p>
                        <ReviewPrompt briefing={briefing} />
                    </div>
                ) : (
                    <Tabs
                        orientation="vertical"
                        className="min-w-0"
                        value={String(selectedIndex)}
                        onValueChange={(value) =>
                            setSelectedIndex(Number(value ?? 0))
                        }
                    >
                        <TabsList
                            variant="line"
                            aria-label={`${briefing.currency} spending change signals`}
                            className="flex w-full flex-col items-stretch gap-1"
                        >
                            {pulse.signals.map((item, index) => {
                                const changedDown =
                                    item.change_minor.startsWith('-');
                                const ChangeIcon = changedDown
                                    ? ArrowDownRight
                                    : ArrowUpRight;

                                return (
                                    <TabsTrigger
                                        key={
                                            item.category.id ??
                                            item.category.name
                                        }
                                        value={String(index)}
                                        data-test={`home-${testSegment}-signal-${index}`}
                                        className="grid h-auto min-h-10 w-full grid-cols-[auto_minmax(0,1fr)_auto] gap-2 px-2 py-2 text-left"
                                    >
                                        <ChangeIcon data-icon="inline-start" />
                                        <span className="min-w-0 truncate">
                                            {item.category.name}
                                        </span>
                                        <span className="tabular-nums">
                                            {changedDown ? '−' : '+'}
                                            {formatMinorUnits(
                                                absoluteAmount(
                                                    item.change_minor,
                                                ),
                                                briefing.currency,
                                            )}
                                        </span>
                                    </TabsTrigger>
                                );
                            })}
                        </TabsList>
                    </Tabs>
                )}
            </div>

            {signal !== undefined && (
                <SignalEvidence
                    briefing={briefing}
                    pulse={pulse}
                    signal={signal}
                />
            )}
        </section>
    );
}

function SignalPanel({ briefings }: { briefings: Briefing[] }) {
    const [selectedCurrency, setSelectedCurrency] = useState(
        briefings[0]?.currency ?? 'PEN',
    );
    const activeBriefing =
        briefings.find((briefing) => briefing.currency === selectedCurrency) ??
        briefings[0];

    return (
        <Card
            className="min-w-0 gap-0 overflow-hidden py-0"
            data-test="home-signals-card"
        >
            <CardHeader className="border-b p-4 sm:p-6">
                <CardTitle>Behind the change</CardTitle>
                <CardDescription>
                    Select a signal to see its Transactions.
                </CardDescription>
                {briefings.length > 1 ? (
                    <Tabs
                        value={activeBriefing?.currency}
                        onValueChange={(value) => {
                            if (value === 'PEN' || value === 'USD') {
                                setSelectedCurrency(value);
                            }
                        }}
                        className="mt-2"
                    >
                        <TabsList className="grid w-full grid-cols-2">
                            {briefings.map((briefing) => (
                                <TabsTrigger
                                    key={briefing.currency}
                                    value={briefing.currency}
                                    data-test={`home-signal-currency-${briefing.currency.toLowerCase()}`}
                                >
                                    {briefing.currency} ·{' '}
                                    {briefing.pulse?.signals.length ?? 0}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                ) : (
                    <Badge variant="secondary" className="mt-2 w-fit">
                        {activeBriefing?.currency}
                    </Badge>
                )}
            </CardHeader>
            <CardContent className="p-0">
                {activeBriefing !== undefined && (
                    <CurrencySignalLane
                        key={activeBriefing.currency}
                        briefing={activeBriefing}
                    />
                )}
            </CardContent>
            {briefings[0] !== undefined && (
                <CardFooter
                    className="grid items-start gap-2 border-t p-4 text-xs text-muted-foreground sm:p-6"
                    data-test="home-coverage-panel"
                >
                    <div className="flex flex-wrap gap-x-4 gap-y-1">
                        {briefings.map((briefing, index) => (
                            <Link
                                key={briefing.currency}
                                href={periodBreakdownUrl({
                                    currency: briefing.currency,
                                    period: briefing.coverage,
                                })}
                                data-test={
                                    index === 0
                                        ? 'home-coverage'
                                        : `home-${briefing.currency.toLowerCase()}-coverage`
                                }
                                className="rounded-sm hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            >
                                <span className="font-medium text-foreground">
                                    {briefing.currency}
                                </span>{' '}
                                {coverageText(briefing)}
                            </Link>
                        ))}
                    </div>
                    <SourceCoverage
                        source={briefings[0].coverage.source}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1"
                        gmailMissingLabel="Gmail has not been checked"
                    />
                </CardFooter>
            )}
        </Card>
    );
}

function HomeBriefing({
    briefings,
    today,
}: {
    briefings: Briefing[];
    today: string;
}) {
    const primary = briefings[0];
    const pulse = primary?.pulse;

    if (primary === undefined || pulse === null) {
        return null;
    }

    const changedDown = pulse.change_minor.startsWith('-');
    const hasChange = pulse.change_minor !== '0';
    const headlineSignal = hasChange
        ? (pulse.signals.find(
              (signal) => signal.change_minor.startsWith('-') === changedDown,
          ) ?? null)
        : null;
    const headline =
        briefings.length > 1
            ? `See how Net Spending changed ${periodReference(primary.period)}.`
            : headlineSignal === null
              ? `Net Spending is steady ${periodReference(primary.period)}.`
              : `${headlineSignal.category.name} is driving the change ${periodReference(primary.period)}.`;

    return (
        <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(36rem,1.2fr)_minmax(20rem,0.8fr)] xl:items-start">
            <Card
                className="min-w-0 gap-0 overflow-hidden py-0"
                data-test="home-overview-card"
            >
                <CardContent className="flex min-w-0 flex-col gap-6 p-4 sm:p-6">
                    <section className="grid gap-3">
                        <div className="grid gap-2">
                            <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                                Your money, in a minute
                            </p>
                            <h2 className="max-w-2xl text-2xl leading-tight font-semibold tracking-tight sm:text-3xl">
                                {headline}
                            </h2>
                            <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm leading-relaxed text-muted-foreground">
                                {briefings.map((briefing) => (
                                    <p key={briefing.currency}>
                                        <span className="font-medium text-foreground">
                                            {briefing.currency}
                                        </span>{' '}
                                        is {spendingChangeDescription(briefing)}
                                        .
                                    </p>
                                ))}
                            </div>
                        </div>
                    </section>

                    <SpendingSnapshot briefings={briefings} />

                    <section className="flex min-w-0 flex-col gap-3">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 className="font-semibold">
                                    Cumulative Net Spending
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {primary.period.label} compared with{' '}
                                    {pulse.previous_period.label}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {briefings.map((briefing) => (
                                    <Badge
                                        key={briefing.currency}
                                        variant="outline"
                                    >
                                        {briefings.length > 1 &&
                                            `${briefing.currency} · `}
                                        {percentageChangeLabel(briefing)} vs
                                        previous
                                    </Badge>
                                ))}
                            </div>
                        </div>
                        <SpendingComparisonChart
                            briefings={briefings}
                            today={today}
                        />
                    </section>
                </CardContent>
            </Card>

            <SignalPanel briefings={briefings} />
        </div>
    );
}

function EmptyHome({ currency }: { currency: Currency | null }) {
    return (
        <Card className="border-dashed">
            <CardHeader className="items-center text-center">
                <CircleDollarSign className="size-8 text-muted-foreground" />
                <CardTitle>
                    {currency === null
                        ? 'No activity'
                        : `No ${currency} activity`}
                </CardTitle>
                <CardDescription>
                    Import a recent statement first. You will check exceptions,
                    confirm it, and open the resulting Breakdown.
                </CardDescription>
            </CardHeader>
            <CardFooter className="justify-center">
                <Button asChild>
                    <Link href={createStatementImport()}>
                        Import a recent statement
                    </Link>
                </Button>
            </CardFooter>
        </Card>
    );
}

function HomeContextBar({ props }: { props: HomeProps }) {
    return (
        <div className="shrink-0" data-test="home-context-bar">
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
                    homeReportingHref({
                        currencyFilter,
                        selection: reportingSelection(props.period),
                    })
                }
            />
        </div>
    );
}

export default function Home(props: HomeProps) {
    const briefings = [props.primary, props.secondary].filter(
        (briefing): briefing is Briefing => briefing !== null,
    );

    return (
        <>
            <Head title="Home" />

            <main className="flex min-h-0 min-w-0 flex-1 flex-col gap-4 p-4 md:p-6 xl:overflow-y-auto">
                <HomeContextBar props={props} />

                {briefings.length === 0 ? (
                    <EmptyHome currency={props.currency_filter} />
                ) : (
                    <HomeBriefing briefings={briefings} today={props.today} />
                )}
            </main>
        </>
    );
}

Home.layout = (props: HomeProps) => ({
    breadcrumbs: [
        {
            title: 'Home',
            href: home({
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
                homeReportingHref({
                    currencyFilter: props.currency_filter,
                    selection,
                })
            }
        />
    ),
    viewportConstrained: true,
});
