// THROWAWAY: three structurally different Trends concepts on /trends?variant=A|B|C.
// Question: should understanding change start with a timeline, a comparison ledger, or a guided explanation?
import { Head, Link, router, usePage } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    SlidersHorizontal,
    TrendingUp,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import { PrototypeSwitcher } from '@/components/prototype-switcher';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { periodBreakdownUrl } from '@/lib/transaction-filter-url';
import { cn } from '@/lib/utils';
import { index as trendsIndex } from '@/routes/trends';
import type { Currency } from '@/types';
import { sampleMonths, sampleTrends } from './trends-prototype-data';
import { findingName, findingUrl } from './index';
import type { Finding, Period, TrendsProps } from './index';

const concepts = [
    { key: 'A', name: 'Horizon', question: 'What is changing over time?' },
    { key: 'B', name: 'Change ledger', question: 'Where did spending change?' },
    { key: 'C', name: 'Focus', question: 'What explains this change?' },
];
const chartConfig = {
    total: { label: 'Net Spending', color: 'var(--chart-2)' },
};
type Mode = 'all' | 'category' | 'merchant';
type ConceptProps = {
    data: TrendsProps;
    sample: boolean;
    mode: Mode;
    setMode: (mode: Mode) => void;
    selected: Finding | undefined;
    selectFinding: (finding: Finding) => void;
    findings: Finding[];
    selectedMonth: string;
    selectMonth: (month: string) => void;
    scenario: boolean;
    setScenario: (enabled: boolean) => void;
    expanded: boolean;
    setExpanded: (expanded: boolean) => void;
};

function money(amount: string | number, currency: Currency) {
    return formatMinorUnits(String(Math.round(Number(amount))), currency);
}

function keyOf(finding: Finding) {
    return `${finding.kind}:${findingName(finding)}`;
}

function Delta({ finding }: { finding: Finding }) {
    const up = Number(finding.change_minor) > 0;
    const Icon = up ? ArrowUpRight : ArrowDownRight;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 font-medium whitespace-nowrap tabular-nums',
                up ? 'text-chart-1' : 'text-chart-2',
            )}
        >
            <Icon className="size-4" />
            <span className="sr-only">{up ? 'Up' : 'Down'} </span>
            {money(Math.abs(Number(finding.change_minor)), finding.currency)}
        </span>
    );
}

function ScopeControl({
    mode,
    setMode,
}: Pick<ConceptProps, 'mode' | 'setMode'>) {
    return (
        <Select value={mode} onValueChange={(value) => setMode(value as Mode)}>
            <SelectTrigger size="sm" aria-label="Group changes by">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem value="all">All changes</SelectItem>
                    <SelectItem value="category">Categories</SelectItem>
                    <SelectItem value="merchant">Merchants</SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}

function BreakdownLink({
    sample,
    data,
    finding,
    period = data.period,
}: {
    sample: boolean;
    data: TrendsProps;
    finding?: Finding;
    period?: Period;
}) {
    if (sample) {
        return (
            <span className="text-xs text-muted-foreground">
                Sample evidence · no linked transactions
            </span>
        );
    }

    return (
        <Button asChild size="sm" variant="outline">
            <Link
                href={
                    finding
                        ? findingUrl(finding, period, period === data.period)
                        : periodBreakdownUrl({
                              currency: data.currency,
                              period,
                          })
                }
            >
                Open in Breakdown
                <ArrowRight data-icon="inline-end" />
            </Link>
        </Button>
    );
}

function ComparisonBars({ finding }: { finding: Finding }) {
    const max = Math.max(
        Math.abs(Number(finding.current_total_minor)),
        Math.abs(Number(finding.typical_total_minor)),
        1,
    );

    return (
        <div
            className="flex flex-col gap-4"
            aria-label={`${findingName(finding)} spending comparison`}
        >
            {[
                {
                    name: 'This period',
                    amount: finding.current_total_minor,
                    current: true,
                },
                {
                    name: 'Typical',
                    amount: finding.typical_total_minor,
                    current: false,
                },
            ].map((item) => (
                <div key={item.name} className="flex flex-col gap-2">
                    <div className="flex justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">
                            {item.name}
                        </span>
                        <span className="font-medium tabular-nums">
                            {money(item.amount, finding.currency)}
                        </span>
                    </div>
                    <div className="h-2 rounded-full bg-muted">
                        <div
                            className={cn(
                                'h-full rounded-full',
                                item.current ? 'bg-chart-1' : 'bg-chart-2',
                            )}
                            style={{
                                width: `${(Math.abs(Number(item.amount)) / max) * 100}%`,
                            }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

function Evidence({
    data,
    selected,
    sample,
}: Pick<ConceptProps, 'data' | 'selected' | 'sample'>) {
    if (!selected) {
        return (
            <p className="p-6 text-sm text-muted-foreground">
                No material changes in this view.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-xs text-muted-foreground">
                        {selected.kind === 'category' ? 'Category' : 'Merchant'}
                    </p>
                    <h3 className="text-xl font-semibold tracking-tight">
                        {findingName(selected)}
                    </h3>
                </div>
                <Delta finding={selected} />
            </div>
            <ComparisonBars finding={selected} />
            <Separator />
            <div className="flex items-end justify-between gap-3">
                <div>
                    <p className="text-xs text-muted-foreground">
                        Transaction frequency
                    </p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                        {selected.current_transaction_count}
                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                            vs {selected.typical_transaction_count} typical
                        </span>
                    </p>
                </div>
                <SlidersHorizontal className="size-4 text-muted-foreground" />
            </div>
            {selected.unusual_transaction && (
                <div className="rounded-lg border border-chart-1/25 bg-chart-1/5 p-3">
                    <p className="text-xs font-medium text-muted-foreground">
                        Unusual transaction
                    </p>
                    <p className="mt-1 text-sm font-medium">
                        {selected.unusual_transaction.description}
                    </p>
                    <p className="mt-1 text-lg font-semibold tabular-nums">
                        {money(
                            selected.unusual_transaction.amount_minor,
                            data.currency,
                        )}
                    </p>
                </div>
            )}
            <div className="flex flex-col gap-2">
                <p className="text-xs text-muted-foreground">
                    Typical is based on these equivalent periods
                </p>
                <div className="flex flex-wrap gap-2">
                    {data.comparison_periods.map((period) =>
                        sample ? (
                            <Badge key={period.date_from} variant="outline">
                                {period.label}
                            </Badge>
                        ) : (
                            <Link
                                key={period.date_from}
                                href={findingUrl(selected, period, false)}
                                className="rounded-md border px-2 py-1 text-xs hover:bg-muted"
                            >
                                {period.label}
                            </Link>
                        ),
                    )}
                </div>
            </div>
            <div>
                <BreakdownLink data={data} sample={sample} finding={selected} />
            </div>
        </div>
    );
}

function MonthChart({
    data,
    selectedMonth,
    selectMonth,
    compact = false,
}: Pick<ConceptProps, 'data' | 'selectedMonth' | 'selectMonth'> & {
    compact?: boolean;
}) {
    const points = data.monthly_context.map((month) => ({
        ...month,
        label: format(parseISO(month.date_from), 'MMM'),
        total:
            month.total_minor === null ? null : Number(month.total_minor) / 100,
    }));

    return (
        <ChartContainer
            config={chartConfig}
            className={cn(
                'aspect-auto w-full',
                compact ? 'h-32' : 'h-60 sm:h-72',
            )}
        >
            <BarChart
                accessibilityLayer
                data={points}
                margin={{ left: 0, right: 8, top: 12, bottom: 0 }}
                onClick={(state) => {
                    if (
                        state.activeTooltipIndex === null ||
                        state.activeTooltipIndex === undefined
                    ) {
                        return;
                    }

                    const point = points[Number(state.activeTooltipIndex)];

                    if (point) {
                        selectMonth(point.month);
                    }
                }}
            >
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="label"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={10}
                />
                <YAxis
                    hide={compact}
                    tickLine={false}
                    axisLine={false}
                    width={48}
                    tickFormatter={(value: number) =>
                        value >= 1000
                            ? `${(value / 1000).toFixed(1)}k`
                            : String(value)
                    }
                />
                <ReferenceLine y={0} stroke="var(--border)" />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            config={chartConfig}
                            formatValue={(value) =>
                                money(Number(value) * 100, data.currency)
                            }
                        />
                    }
                />
                <Bar
                    dataKey="total"
                    name="total"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={64}
                    isAnimationActive={false}
                >
                    {points.map((point) => (
                        <Cell
                            key={point.month}
                            fill="var(--chart-2)"
                            fillOpacity={
                                point.month === selectedMonth ? 1 : 0.28
                            }
                            stroke={
                                point.month ===
                                data.period.date_from.slice(0, 7)
                                    ? 'var(--chart-2)'
                                    : 'none'
                            }
                            strokeDasharray={
                                point.month ===
                                data.period.date_from.slice(0, 7)
                                    ? '4 3'
                                    : undefined
                            }
                        />
                    ))}
                </Bar>
            </BarChart>
        </ChartContainer>
    );
}

export function VariantA(props: ConceptProps) {
    const {
        data,
        sample,
        selected,
        findings,
        selectFinding,
        selectedMonth,
        selectMonth,
    } = props;
    const month =
        data.monthly_context.find((item) => item.month === selectedMonth) ??
        data.monthly_context.at(-1);

    return (
        <div className="flex flex-col gap-5">
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <CardDescription>
                                Net Spending over time · {data.currency}
                            </CardDescription>
                            <CardTitle className="mt-2">
                                {month?.label ?? data.period.label}
                            </CardTitle>
                            <p className="mt-2 text-3xl font-semibold tracking-tight tabular-nums">
                                {month?.total_minor == null
                                    ? 'No recorded activity'
                                    : money(month.total_minor, data.currency)}
                            </p>
                        </div>
                        <div className="flex flex-col items-end gap-2">
                            <Badge variant="outline">Six-month view</Badge>
                            <p className="max-w-52 text-right text-xs text-muted-foreground">
                                Select a bar to inspect a month. The analysis
                                month ends on{' '}
                                {format(parseISO(data.period.date_to), 'MMM d')}
                                .
                            </p>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <MonthChart {...props} />
                    <div className="grid grid-cols-3 gap-1 sm:grid-cols-6">
                        {data.monthly_context.map((item) => (
                            <Button
                                key={item.month}
                                variant={
                                    item.month === month?.month
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                size="sm"
                                aria-pressed={item.month === month?.month}
                                onClick={() => selectMonth(item.month)}
                            >
                                {format(parseISO(item.date_from), 'MMM')}
                                {item.month ===
                                    data.period.date_from.slice(0, 7) && (
                                    <span className="text-xs">· to date</span>
                                )}
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs text-muted-foreground">
                            Calendar-month totals. A partial month is not a
                            full-month comparison.
                        </p>
                        {month && (
                            <BreakdownLink
                                data={data}
                                sample={sample}
                                period={month}
                            />
                        )}
                    </div>
                </CardContent>
            </Card>
            <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <section className="min-w-0">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="font-semibold">
                                What changed this period
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                {data.period.label} · compared with equivalent
                                days
                            </p>
                        </div>
                        <ScopeControl {...props} />
                    </div>
                    <div className="flex flex-col gap-1">
                        {findings.map((finding) => (
                            <button
                                key={keyOf(finding)}
                                onClick={() => selectFinding(finding)}
                                aria-pressed={selected === finding}
                                className={cn(
                                    'grid grid-cols-[1fr_auto] items-center gap-4 rounded-lg border border-transparent p-3 text-left transition-colors hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring',
                                    selected === finding &&
                                        'border-border bg-muted/50',
                                )}
                            >
                                <span>
                                    <span className="block text-sm font-medium">
                                        {findingName(finding)}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {finding.kind === 'category'
                                            ? 'Category'
                                            : 'Merchant'}{' '}
                                        · {finding.current_transaction_count}{' '}
                                        transactions
                                    </span>
                                </span>
                                <Delta finding={finding} />
                            </button>
                        ))}
                    </div>
                </section>
                <Card>
                    <CardHeader>
                        <CardTitle>Behind the change</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Evidence
                            data={data}
                            selected={selected}
                            sample={sample}
                        />
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

export function VariantB(props: ConceptProps) {
    const { data, findings, selected, selectFinding } = props;
    const expandedFinding = props.expanded ? selected : undefined;

    function toggleFinding(finding: Finding) {
        props.setExpanded(expandedFinding !== finding);
        selectFinding(finding);
    }
    const max = Math.max(
        ...findings.map((finding) => Math.abs(Number(finding.change_minor))),
        1,
    );

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-2">
                <h2 className="text-2xl font-semibold tracking-tight">
                    The change ledger
                </h2>
                <p className="text-sm text-muted-foreground">
                    See the differences side by side. Expand a row to inspect
                    its evidence.
                </p>
            </div>
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_15rem]">
                <section className="min-w-0 rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                        <div className="flex items-center gap-2">
                            <h3 className="font-semibold">Changes by impact</h3>
                            <Badge variant="secondary">{findings.length}</Badge>
                        </div>
                        <ScopeControl {...props} />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">
                                    Category / merchant
                                </TableHead>
                                <TableHead className="text-right">
                                    This period
                                </TableHead>
                                <TableHead className="text-right">
                                    Typical
                                </TableHead>
                                <TableHead className="text-right">
                                    Change
                                </TableHead>
                                <TableHead className="hidden w-28 text-center xl:table-cell">
                                    Lower / higher
                                </TableHead>
                                <TableHead className="w-8">
                                    <span className="sr-only">Evidence</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {findings.map((finding) => (
                                <Fragment key={keyOf(finding)}>
                                    <TableRow
                                        className={cn(
                                            expandedFinding === finding &&
                                                'bg-muted/50',
                                        )}
                                    >
                                        <TableCell className="pl-4">
                                            <button
                                                className="flex flex-col gap-1 py-1 text-left focus-visible:ring-2 focus-visible:ring-ring"
                                                onClick={() =>
                                                    toggleFinding(finding)
                                                }
                                                aria-expanded={
                                                    expandedFinding === finding
                                                }
                                            >
                                                <span className="font-medium">
                                                    {findingName(finding)}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {finding.kind === 'category'
                                                        ? 'Category'
                                                        : 'Merchant'}
                                                </span>
                                            </button>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(
                                                finding.current_total_minor,
                                                data.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                            {money(
                                                finding.typical_total_minor,
                                                data.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Delta finding={finding} />
                                        </TableCell>
                                        <TableCell className="hidden xl:table-cell">
                                            <div className="relative h-6">
                                                <div className="absolute inset-y-0 left-1/2 w-px bg-border" />
                                                <div
                                                    className={cn(
                                                        'absolute top-2 h-2 rounded-sm',
                                                        Number(
                                                            finding.change_minor,
                                                        ) > 0
                                                            ? 'left-1/2 bg-chart-1'
                                                            : 'right-1/2 bg-chart-2',
                                                    )}
                                                    style={{
                                                        width: `${(Math.abs(Number(finding.change_minor)) / max) * 48}%`,
                                                    }}
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Inspect ${findingName(finding)}`}
                                                aria-expanded={
                                                    expandedFinding === finding
                                                }
                                                onClick={() =>
                                                    toggleFinding(finding)
                                                }
                                            >
                                                <ChevronDown
                                                    className={cn(
                                                        expandedFinding ===
                                                            finding &&
                                                            'rotate-180',
                                                    )}
                                                />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                    {expandedFinding === finding && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={6}
                                                className="bg-muted/20 p-5 whitespace-normal"
                                            >
                                                <div className="max-w-xl">
                                                    <Evidence
                                                        data={data}
                                                        selected={selected}
                                                        sample={props.sample}
                                                    />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                    <p className="border-t p-4 text-xs text-muted-foreground">
                        Category and merchant views overlap. Their changes
                        should not be added together.
                    </p>
                </section>
                <aside className="flex flex-col gap-5">
                    <div className="flex flex-col gap-4 rounded-xl bg-muted/40 p-4">
                        <p className="text-xs font-medium text-muted-foreground">
                            THIS PERIOD · {data.currency}
                        </p>
                        <p className="text-2xl font-semibold tabular-nums">
                            {data.summary
                                ? money(
                                      data.summary.net_spending_minor,
                                      data.currency,
                                  )
                                : 'No activity'}
                        </p>
                        <p className="text-sm">Net Spending</p>
                        <Separator />
                        <p className="text-xs text-muted-foreground">
                            Spending minus refunds and reimbursements. Income
                            and transfers stay separate.
                        </p>
                        <BreakdownLink data={data} sample={props.sample} />
                    </div>
                    <div>
                        <h3 className="mb-2 text-sm font-medium">
                            Calendar-month context
                        </h3>
                        <MonthChart {...props} compact />
                        <p className="mt-2 text-xs text-muted-foreground">
                            The analysis month ends on{' '}
                            {format(parseISO(data.period.date_to), 'MMM d')}.
                            Select a month to inspect its total below.
                        </p>
                        <p className="mt-2 text-sm font-medium tabular-nums">
                            {props.selectedMonth} ·{' '}
                            {data.monthly_context.find(
                                (item) => item.month === props.selectedMonth,
                            )?.total_minor == null
                                ? 'No activity'
                                : money(
                                      data.monthly_context.find(
                                          (item) =>
                                              item.month ===
                                              props.selectedMonth,
                                      )!.total_minor!,
                                      data.currency,
                                  )}
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    );
}

export function VariantC(props: ConceptProps) {
    const { data, selected, findings, selectFinding, scenario, setScenario } =
        props;

    if (!selected) {
        return (
            <p className="py-12 text-center text-muted-foreground">
                No material changes to explain in this view.
            </p>
        );
    }

    const index = findings.indexOf(selected);
    const up = Number(selected.change_minor) > 0;
    const total = Number(data.summary?.net_spending_minor ?? 0);
    const scenarioTotal =
        total - Number(selected.scenario?.difference_minor ?? 0);

    return (
        <div className="grid items-start gap-6 lg:grid-cols-[13rem_minmax(0,1fr)] xl:gap-10">
            <nav
                aria-label="Changes to explore"
                className="flex flex-col gap-4"
            >
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-sm font-semibold">
                        Explore the changes
                    </h2>
                    <ScopeControl {...props} />
                </div>
                <div className="flex gap-2 overflow-x-auto lg:flex-col">
                    {findings.map((finding, i) => (
                        <button
                            key={keyOf(finding)}
                            onClick={() => selectFinding(finding)}
                            aria-current={
                                selected === finding ? 'true' : undefined
                            }
                            className={cn(
                                'flex shrink-0 items-start gap-3 rounded-lg p-3 text-left hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:shrink',
                                selected === finding && 'bg-muted',
                            )}
                        >
                            <span className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            <span className="flex flex-col gap-2">
                                <span className="text-sm font-medium">
                                    {findingName(finding)}
                                </span>
                                <span className="text-xs">
                                    <Delta finding={finding} />
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
                <p className="hidden text-xs leading-relaxed text-muted-foreground lg:block">
                    Ranked by financial impact. Categories and merchants can
                    describe the same spending.
                </p>
            </nav>
            <article className="flex min-w-0 flex-col gap-7">
                <header className="flex flex-col gap-4">
                    <div className="flex items-center justify-between">
                        <Badge variant="outline">
                            Change {index + 1} of {findings.length}
                        </Badge>
                        <div className="flex gap-1">
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="Previous finding"
                                onClick={() =>
                                    selectFinding(
                                        findings[
                                            (index - 1 + findings.length) %
                                                findings.length
                                        ],
                                    )
                                }
                            >
                                <ChevronLeft />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="Next finding"
                                onClick={() =>
                                    selectFinding(
                                        findings[(index + 1) % findings.length],
                                    )
                                }
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>
                    <h2 className="max-w-2xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
                        {findingName(selected)} is{' '}
                        <span className={up ? 'text-chart-1' : 'text-chart-2'}>
                            {money(
                                Math.abs(Number(selected.change_minor)),
                                data.currency,
                            )}{' '}
                            {up ? 'higher' : 'lower'}
                        </span>{' '}
                        than typical.
                    </h2>
                    <p className="max-w-xl text-sm leading-relaxed text-muted-foreground">
                        {data.period.label}. Compared with the same days in the
                        previous three months.
                    </p>
                </header>
                <div className="grid gap-8 sm:grid-cols-2">
                    <div className="flex flex-col gap-5">
                        <h3 className="text-sm font-semibold">
                            The spending difference
                        </h3>
                        <ComparisonBars finding={selected} />
                    </div>
                    <div className="flex flex-col gap-3 border-l-2 border-chart-2/40 pl-5">
                        <p className="text-sm font-semibold">
                            What the evidence shows
                        </p>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {selected.current_transaction_count} transactions
                            this period, compared with{' '}
                            {selected.typical_transaction_count} typical.
                            {selected.unusual_transaction
                                ? ' There is also an unusually large transaction.'
                                : ' Compare the transactions to see what changed.'}
                        </p>
                        {selected.unusual_transaction && (
                            <div className="flex flex-wrap justify-between gap-2 text-sm">
                                <span>
                                    {selected.unusual_transaction.description}
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {money(
                                        selected.unusual_transaction
                                            .amount_minor,
                                        data.currency,
                                    )}
                                </span>
                            </div>
                        )}
                        <div>
                            <BreakdownLink
                                data={data}
                                sample={props.sample}
                                finding={selected}
                            />
                        </div>
                    </div>
                </div>
                <Separator />
                {selected.scenario && (
                    <section className="grid items-center gap-5 rounded-xl bg-muted/50 p-5 sm:grid-cols-[1fr_auto]">
                        <div className="flex flex-col gap-2">
                            <h3 className="font-semibold">
                                What if {findingName(selected)} returned to
                                typical?
                            </h3>
                            <p className="max-w-lg text-sm text-muted-foreground">
                                Hold everything else fixed and reduce this
                                change by{' '}
                                {money(
                                    selected.scenario.difference_minor,
                                    data.currency,
                                )}
                                .
                            </p>
                            <div className="mt-1">
                                <Button
                                    variant={scenario ? 'secondary' : 'outline'}
                                    size="sm"
                                    aria-pressed={scenario}
                                    onClick={() => setScenario(!scenario)}
                                >
                                    {scenario
                                        ? 'Show actual spending'
                                        : 'Try typical spending'}
                                </Button>
                            </div>
                        </div>
                        <div aria-live="polite">
                            <p className="text-xs text-muted-foreground">
                                {scenario
                                    ? 'Scenario Net Spending'
                                    : 'Actual Net Spending'}
                            </p>
                            <p className="mt-1 text-3xl font-semibold tracking-tight tabular-nums">
                                {money(
                                    scenario ? scenarioTotal : total,
                                    data.currency,
                                )}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {scenario
                                    ? 'Illustration only · nothing is changed'
                                    : 'For this period'}
                            </p>
                        </div>
                    </section>
                )}
                <div className="flex flex-col gap-3">
                    <h3 className="text-sm font-semibold">
                        Check the comparison
                    </h3>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {data.comparison_periods.map((period) => (
                            <div
                                key={period.date_from}
                                className="flex flex-col gap-3 rounded-lg border p-3"
                            >
                                <p className="text-sm font-medium">
                                    {period.label}
                                </p>
                                <BreakdownLink
                                    data={data}
                                    sample={props.sample}
                                    finding={selected}
                                    period={period}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </article>
        </div>
    );
}

export default function TrendsPrototype(live: TrendsProps) {
    const { url } = usePage();
    const params = new URL(url, 'http://localhost').searchParams;
    const variant = params.get('variant') ?? 'A';
    const sample = params.get('data') !== 'live';
    const month = sampleMonths.includes(params.get('month') ?? '')
        ? params.get('month')!
        : '2026-09';
    const currency: Currency = params.get('currency') === 'USD' ? 'USD' : 'PEN';
    const data = sample ? sampleTrends(month, currency) : live;
    const [mode, setMode] = useState<Mode>('all');
    const [selectedKey, setSelectedKey] = useState<string | null>(null);
    const [contextMonth, setContextMonth] = useState<string | null>(null);
    const [scenario, setScenario] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const findings = data.findings.filter(
        (finding) => mode === 'all' || finding.kind === mode,
    );
    const selected =
        findings.find((finding) => keyOf(finding) === selectedKey) ??
        findings[0];
    const selectedMonth = data.monthly_context.some(
        (item) => item.month === contextMonth,
    )
        ? contextMonth!
        : data.period.date_from.slice(0, 7);

    function updateQuery(changes: Record<string, string>) {
        Object.entries(changes).forEach(([key, value]) =>
            params.set(key, value),
        );
        const nextUrl = trendsIndex.url({ query: Object.fromEntries(params) });

        if (params.get('data') === 'live') {
            router.get(nextUrl, {}, { preserveScroll: true });
        } else {
            router.replace({
                url: nextUrl,
                preserveState: true,
                preserveScroll: true,
            });
        }

        setScenario(false);
        setContextMonth(null);
    }
    const conceptProps: ConceptProps = {
        data,
        sample,
        mode,
        setMode: (value) => {
            setMode(value);
            setScenario(false);
        },
        findings,
        selected,
        selectFinding: (finding) => {
            setSelectedKey(keyOf(finding));
            setScenario(false);
        },
        selectedMonth,
        selectMonth: setContextMonth,
        scenario,
        setScenario,
        expanded,
        setExpanded,
    };
    const monthIndex = sampleMonths.indexOf(month);

    return (
        <>
            <Head title="Trends concepts" />
            <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 pb-40 md:p-6 md:pb-40">
                <header className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <TrendingUp className="size-5 text-muted-foreground" />
                            <h1 className="text-xl font-semibold tracking-tight">
                                Trends
                            </h1>
                            <Badge variant="outline">
                                {sample ? 'Sample data' : 'Live data'}
                            </Badge>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={sample ? 'sample' : 'live'}
                                onValueChange={(value) =>
                                    updateQuery({ data: value })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    aria-label="Prototype data source"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="sample">
                                            Sample data
                                        </SelectItem>
                                        <SelectItem value="live">
                                            My live data
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <Select
                                value={data.currency}
                                onValueChange={(value) =>
                                    updateQuery({ currency: value })
                                }
                            >
                                <SelectTrigger size="sm" aria-label="Currency">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="PEN">PEN</SelectItem>
                                        <SelectItem value="USD">USD</SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            {
                                concepts.find(
                                    (concept) => concept.key === variant,
                                )?.question
                            }
                        </p>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Previous month"
                                disabled={!sample || monthIndex === 0}
                                onClick={() =>
                                    updateQuery({
                                        month: sampleMonths[monthIndex - 1],
                                    })
                                }
                            >
                                <ChevronLeft />
                            </Button>
                            <Select
                                value={
                                    sample
                                        ? month
                                        : data.period.date_from.slice(0, 7)
                                }
                                disabled={!sample}
                                onValueChange={(value) =>
                                    updateQuery({ month: value })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    aria-label="Analysis month"
                                    className="w-44"
                                >
                                    <SelectValue>
                                        {format(
                                            parseISO(data.period.date_from),
                                            'MMMM yyyy',
                                        )}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {sampleMonths.map((item) => (
                                            <SelectItem key={item} value={item}>
                                                {format(
                                                    parseISO(`${item}-01`),
                                                    'MMMM yyyy',
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Next month"
                                disabled={
                                    !sample ||
                                    monthIndex === sampleMonths.length - 1
                                }
                                onClick={() =>
                                    updateQuery({
                                        month: sampleMonths[monthIndex + 1],
                                    })
                                }
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>
                            {data.period.label} · vs equivalent days in the
                            previous three months
                        </span>
                        <span>
                            {sample
                                ? 'Historical navigation uses sample data'
                                : 'Live findings are available for the current month'}
                        </span>
                    </div>
                </header>
                {data.summary === null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                No {data.currency} activity this period
                            </CardTitle>
                            <CardDescription>
                                Recorded transactions will appear here. Choose
                                Sample data to explore all three concepts.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}
                {variant === 'B' ? (
                    <VariantB {...conceptProps} />
                ) : variant === 'C' ? (
                    <VariantC {...conceptProps} />
                ) : (
                    <VariantA {...conceptProps} />
                )}
            </main>
            <PrototypeSwitcher
                title="Trends"
                concepts={concepts}
                route={trendsIndex.url}
                state={{
                    source: sample ? 'sample' : 'live',
                    period: data.period,
                    currency: data.currency,
                    group: mode,
                    selectedFinding: selected ?? null,
                    selectedContextMonth: selectedMonth,
                    scenario,
                    expanded,
                    summary: data.summary,
                    comparisonPeriods: data.comparison_periods,
                    monthlyContext: data.monthly_context,
                }}
            />
        </>
    );
}
