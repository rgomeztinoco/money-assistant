// THROWAWAY: three further Home concepts on /?variant=D|E|F. Run Sail pnpm prototype:home.
// Question: is Home most useful as a change digest, a spending simulator, or a finite check-in?
// All financial examples are fictional. Each month is viewed on day 10; changes stay in memory.
import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Clock3,
    Coffee,
    Info,
    ListChecks,
    PiggyBank,
    RotateCcw,
    Sparkles,
    Target,
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
import { PrototypeSwitcher } from '@/components/prototype-switcher';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { Currency } from '@/types';
import type { Briefing } from './home';

const concepts = [
    { key: 'D', name: 'The pulse', question: 'What changed?' },
    { key: 'E', name: 'Month ahead', question: 'What could I change?' },
    { key: 'F', name: 'Money check-in', question: 'What can I finish?' },
];
const months = ['2026-07', '2026-08', '2026-09'];
const monthNames = ['July', 'August', 'September'];
const chartConfig = {
    recorded: { label: 'This month', color: 'var(--chart-1)' },
    previous: { label: 'Previous month', color: 'var(--muted-foreground)' },
    planned: { label: 'Your scenario', color: 'var(--chart-2)' },
};
const penCategories = [
    {
        name: 'Food & dining',
        current: 628,
        previous: 384,
        icon: Coffee,
        reason: 'Two more restaurant visits account for most of the change.',
        evidence: [
            ['Restaurant visit', 196],
            ['Weekend dinner', 184],
            ['Lunches', 148],
            ['Coffee & snacks', 100],
        ],
    },
    {
        name: 'Shopping',
        current: 396,
        previous: 234,
        icon: ArrowUpRight,
        reason: 'One larger purchase, rather than more frequent shopping.',
        evidence: [
            ['Running shoes', 280],
            ['Household supplies', 116],
        ],
    },
    {
        name: 'Transport',
        current: 160,
        previous: 230,
        icon: ArrowRight,
        reason: 'Fewer ride-hailing trips in the first ten days.',
        evidence: [
            ['Ride-hailing', 108],
            ['Public transport', 52],
        ],
    },
];
const usdCategories = [
    {
        name: 'Software',
        current: 92,
        previous: 60,
        icon: ArrowUpRight,
        reason: 'An annual subscription renewed this month.',
        evidence: [
            ['Annual subscription', 60],
            ['Monthly tools', 32],
        ],
    },
    {
        name: 'Shopping',
        current: 75,
        previous: 65,
        icon: ArrowUpRight,
        reason: 'A small increase in online purchases.',
        evidence: [['Online purchase', 75]],
    },
    {
        name: 'Entertainment',
        current: 20,
        previous: 30,
        icon: Coffee,
        reason: 'One fewer streaming subscription this month.',
        evidence: [['Streaming subscriptions', 20]],
    },
];

function example(month: string, currency: Currency) {
    const index = months.indexOf(month);
    const factor = [0.85, 0.93, 1][index];
    const categories = (currency === 'PEN' ? penCategories : usdCategories).map(
        (category) => ({
            ...category,
            current: Math.round(category.current * factor),
            previous: Math.round(category.previous * factor),
            evidence: category.evidence.map(([label, value], i, rows) => ({
                label: String(label),
                amount:
                    i === rows.length - 1
                        ? Math.round(category.current * factor) -
                          rows
                              .slice(0, -1)
                              .reduce(
                                  (total, row) =>
                                      total +
                                      Math.round(Number(row[1]) * factor),
                                  0,
                              )
                        : Math.round(Number(value) * factor),
            })),
        }),
    );
    const other = Math.round((currency === 'PEN' ? 662 : 48) * factor);
    const spending = categories.reduce(
        (total, category) => total + category.current,
        other,
    );
    const previous = categories.reduce(
        (total, category) => total + category.previous,
        other,
    );

    return {
        month,
        currency,
        categories,
        spending,
        previous,
        label: monthNames[index],
        days: month === '2026-09' ? 30 : 31,
        income: currency === 'PEN' ? 7200 : 900,
        savings: currency === 'PEN' ? 1200 : 150,
        target: currency === 'PEN' ? 4800 : 600,
        daily: currency === 'PEN' ? 55 : 8,
        upcoming:
            currency === 'PEN'
                ? [
                      { name: 'Rent', day: 15, amount: 1450 },
                      { name: 'Internet', day: 18, amount: 99 },
                  ]
                : [{ name: 'Professional membership', day: 18, amount: 95 }],
        extra: currency === 'PEN' ? 420 : 80,
        transactions: currency === 'PEN' ? 42 : 12,
    };
}
type Example = ReturnType<typeof example>;
const money = (value: number, currency: Currency) =>
    formatMinorUnits(String(Math.round(value * 100)), currency);

function SpendingLine({
    data,
    projection,
}: {
    data: Example;
    projection?: number;
}) {
    const weights = [0, 0.04, 0.09, 0.16, 0.19, 0.27, 0.32, 0.45, 0.55, 0.8, 1];
    const points: {
        day: number;
        recorded?: number;
        previous?: number;
        planned?: number;
    }[] = weights.map((weight, day) => ({
        day,
        recorded: Math.round(data.spending * weight),
        previous:
            projection === undefined
                ? Math.round((data.previous * day) / 10)
                : undefined,
        planned:
            projection !== undefined && day === 10 ? data.spending : undefined,
    }));

    if (projection !== undefined) {
        points.push({
            day: data.days,
            recorded: undefined,
            previous: undefined,
            planned: projection,
        });
    }

    return (
        <ChartContainer
            config={chartConfig}
            className="aspect-auto h-56 w-full"
            aria-label={
                projection === undefined
                    ? 'Cumulative spending through day 10 compared with the previous month'
                    : 'Recorded spending and a straight line to the simulated month-end total'
            }
        >
            <LineChart
                accessibilityLayer
                data={points}
                margin={{ left: -18, right: 12, top: 15, bottom: 0 }}
            >
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="day"
                    type="number"
                    domain={[0, projection === undefined ? 10 : data.days]}
                    ticks={
                        projection === undefined
                            ? [1, 3, 5, 7, 10]
                            : [1, 10, 20, data.days]
                    }
                    tickFormatter={(day) => `${data.label.slice(0, 3)} ${day}`}
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    width={60}
                    tickFormatter={(value) =>
                        Number(value) >= 1000
                            ? `${Number(value) / 1000}k`
                            : String(value)
                    }
                />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            config={chartConfig}
                            formatLabel={(day) => `${data.label} ${day}`}
                            formatValue={(value) =>
                                money(Number(value), data.currency)
                            }
                        />
                    }
                />
                {projection !== undefined && (
                    <ReferenceLine
                        y={data.target}
                        stroke="var(--muted-foreground)"
                        strokeDasharray="3 4"
                        label={{
                            value: 'Target',
                            position: 'insideTopRight',
                            fill: 'var(--muted-foreground)',
                            fontSize: 11,
                        }}
                    />
                )}
                <Line
                    dataKey="previous"
                    isAnimationActive={false}
                    stroke="var(--color-previous)"
                    strokeDasharray="4 5"
                    strokeWidth={1.5}
                    dot={false}
                />
                <Line
                    dataKey="recorded"
                    isAnimationActive={false}
                    stroke="var(--color-recorded)"
                    strokeWidth={2.5}
                    dot={false}
                />
                <Line
                    dataKey="planned"
                    isAnimationActive={false}
                    stroke="var(--color-planned)"
                    strokeDasharray="5 5"
                    strokeWidth={2.5}
                    dot={false}
                />
            </LineChart>
        </ChartContainer>
    );
}

function Snapshot({ data }: { data: Example }) {
    return (
        <dl className="grid grid-cols-1 divide-y border-y sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {[
                {
                    name: 'Net spending',
                    value: data.spending,
                    icon: ArrowUpRight,
                    note: 'Spending less refunds',
                },
                {
                    name: 'Income',
                    value: data.income,
                    icon: ArrowDownLeft,
                    note: 'Recorded this month',
                },
                {
                    name: 'Moved to savings',
                    value: data.savings,
                    icon: PiggyBank,
                    note: 'Transfers less withdrawals',
                },
            ].map((item) => (
                <div
                    key={item.name}
                    className="grid gap-1 px-4 py-4 first:pl-0"
                >
                    <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                        <item.icon className="size-3.5" />
                        {item.name}
                    </dt>
                    <dd className="text-xl font-semibold tracking-tight tabular-nums">
                        {money(item.value, data.currency)}
                    </dd>
                    <dd className="text-xs text-muted-foreground">
                        {item.note}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function VariantD({
    data,
    selected,
    onSelect,
    remaining,
}: {
    data: Example;
    selected: number;
    onSelect: (value: number) => void;
    remaining: number;
}) {
    const category = data.categories[selected];
    const change = data.spending - data.previous;

    return (
        <div className="grid gap-7">
            <div className="grid gap-8 lg:grid-cols-[minmax(0,1.8fr)_minmax(260px,1fr)]">
                <section className="min-w-0">
                    <p className="mb-3 text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        Your month, in a minute
                    </p>
                    <h2 className="max-w-2xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
                        {data.categories[0].name} is driving
                        <br className="hidden xl:block" /> the change this
                        month.
                    </h2>
                    <p className="mt-4 max-w-xl text-sm leading-relaxed text-muted-foreground">
                        Net spending is{' '}
                        <span className="font-medium text-foreground">
                            {money(change, data.currency)} higher
                        </span>{' '}
                        than the first ten days of last month.{' '}
                        {data.categories[0].reason}
                    </p>
                    <div className="mt-7 flex flex-wrap items-baseline justify-between gap-3">
                        <div>
                            <span className="text-3xl font-semibold tracking-tight tabular-nums">
                                {money(data.spending, data.currency)}
                            </span>
                            <span className="ml-2 text-xs text-muted-foreground">
                                spent through {data.label.slice(0, 3)} 10
                            </span>
                        </div>
                        <Badge variant="outline">
                            +{Math.round((change / data.previous) * 100)}% vs
                            last month
                        </Badge>
                    </div>
                    <div className="mt-4">
                        <SpendingLine data={data} />
                    </div>
                    <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                        <span className="flex items-center gap-2">
                            <span className="h-0.5 w-4 bg-chart-1" />
                            This month
                        </span>
                        <span className="flex items-center gap-2">
                            <span className="w-4 border-t border-dashed border-muted-foreground" />
                            Last month, same days
                        </span>
                        <span className="ml-auto">
                            {data.currency} · cumulative
                        </span>
                    </div>
                </section>
                <aside className="min-w-0 rounded-xl border bg-muted/20 p-5">
                    <div className="flex items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">
                            Behind the change
                        </h3>
                        <Badge variant="secondary">3 signals</Badge>
                    </div>
                    <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                        Select a signal to see what contributed.
                    </p>
                    <div className="mt-4 flex flex-col gap-2">
                        {data.categories.map((item, index) => (
                            <button
                                type="button"
                                key={item.name}
                                aria-pressed={selected === index}
                                onClick={() => onSelect(index)}
                                className={cn(
                                    'flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    selected === index
                                        ? 'border-foreground/25 bg-background shadow-sm'
                                        : 'border-transparent hover:bg-muted',
                                )}
                            >
                                <item.icon className="size-4 shrink-0 text-muted-foreground" />
                                <span className="min-w-0 flex-1 text-sm font-medium">
                                    {item.name}
                                </span>
                                <span className="text-sm tabular-nums">
                                    {item.current >= item.previous ? '+' : '−'}
                                    {money(
                                        Math.abs(item.current - item.previous),
                                        data.currency,
                                    )}
                                </span>
                            </button>
                        ))}
                    </div>
                    <Separator className="my-4" />
                    <div aria-live="polite">
                        <h4 className="text-sm font-medium">{category.name}</h4>
                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            {category.reason}
                        </p>
                        <dl className="mt-3 grid gap-2">
                            {category.evidence.map((row) => (
                                <div
                                    key={row.label}
                                    className="flex justify-between gap-3 text-xs"
                                >
                                    <dt className="text-muted-foreground">
                                        {row.label}
                                    </dt>
                                    <dd className="shrink-0 tabular-nums">
                                        {money(row.amount, data.currency)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mt-4 text-xs text-muted-foreground">
                            Example evidence · {data.label} 1–10
                        </p>
                    </div>
                </aside>
            </div>
            <Snapshot data={data} />
            <div className="grid gap-5 md:grid-cols-2">
                <section className="flex items-start gap-3">
                    <CircleCheck className="mt-1 size-5 shrink-0 text-chart-2" />
                    <div>
                        <h3 className="text-sm font-medium">
                            Savings are still happening
                        </h3>
                        <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                            {money(data.savings, data.currency)} moved to
                            savings so far, alongside{' '}
                            {money(data.income, data.currency)} in recorded
                            income.
                        </p>
                    </div>
                </section>
                <section className="flex items-start gap-3">
                    <ListChecks className="mt-1 size-5 shrink-0 text-muted-foreground" />
                    <div>
                        <h3 className="text-sm font-medium">
                            {remaining === 0
                                ? 'Your categories are caught up'
                                : `${remaining} ${remaining === 1 ? 'transaction needs' : 'transactions need'} a category`}
                        </h3>
                        <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                            {remaining === 0
                                ? 'The three example transactions now have categories. Net spending stays the same.'
                                : 'They already count toward spending. A quick check-in makes the category picture more useful.'}
                        </p>
                        <Button
                            variant="link"
                            size="sm"
                            className="mt-1 px-0"
                            onClick={() =>
                                router.replace({
                                    url: home.url({
                                        query: {
                                            variant: 'F',
                                            month: data.month,
                                            currency: data.currency,
                                        },
                                    }),
                                    preserveState: true,
                                })
                            }
                        >
                            Try the money check-in
                            <ArrowRight />
                        </Button>
                    </div>
                </section>
            </div>
        </div>
    );
}

export function VariantE({
    data,
    daily,
    extra,
    onDaily,
    onExtra,
}: {
    data: Example;
    daily: number;
    extra: boolean;
    onDaily: (value: number) => void;
    onExtra: (value: boolean) => void;
}) {
    const remainingDays = data.days - 10;
    const commitments = data.upcoming.reduce(
        (total, item) => total + item.amount,
        0,
    );
    const projected =
        data.spending +
        commitments +
        daily * remainingDays +
        (extra ? data.extra : 0);
    const room = data.target - projected;

    return (
        <div className="grid gap-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="mb-2 text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        Look forward
                    </p>
                    <h2 className="text-3xl font-semibold tracking-tight">
                        Give the rest of the month a plan.
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Try a daily allowance and see where spending would land.
                    </p>
                </div>
                <Badge variant="outline">
                    <Target className="size-3" />
                    Example target {money(data.target, data.currency)}
                </Badge>
            </header>
            <div className="grid gap-6 lg:grid-cols-[minmax(270px,0.85fr)_minmax(0,1.6fr)]">
                <section className="flex min-w-0 flex-col gap-6 rounded-xl border p-5">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold">Try a scenario</h3>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Reset scenario"
                            onClick={() => {
                                onDaily(data.daily);
                                onExtra(false);
                            }}
                        >
                            <RotateCcw />
                        </Button>
                    </div>
                    <div className="grid gap-3">
                        <label
                            htmlFor="daily-spending"
                            className="flex flex-wrap items-center justify-between gap-2 text-sm"
                        >
                            Everyday spending{' '}
                            <output
                                htmlFor="daily-spending"
                                className="font-semibold tabular-nums"
                            >
                                {money(daily, data.currency)} / day
                            </output>
                        </label>
                        <input
                            id="daily-spending"
                            type="range"
                            min="0"
                            max={data.currency === 'PEN' ? 160 : 30}
                            step="1"
                            value={daily}
                            onChange={(event) =>
                                onDaily(Number(event.target.value))
                            }
                            className="h-6 w-full cursor-pointer accent-primary"
                        />
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            For the remaining {remainingDays} days. Include
                            food, transport, and other everyday purchases.
                        </p>
                        <div
                            className="grid gap-1 rounded-lg bg-muted p-3 lg:hidden"
                            role="status"
                        >
                            <p className="text-xs text-muted-foreground">
                                Simulated month-end spending
                            </p>
                            <p className="text-xl font-semibold tabular-nums">
                                {money(projected, data.currency)}
                            </p>
                            <p
                                className={cn(
                                    'text-xs',
                                    room < 0
                                        ? 'text-destructive'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {money(Math.abs(room), data.currency)}{' '}
                                {room < 0 ? 'over' : 'under'} target
                            </p>
                        </div>
                    </div>
                    <Separator />
                    <div>
                        <p className="text-sm font-medium">
                            Already in this plan
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Example commitments, entered by you.
                        </p>
                        <div className="mt-4 grid gap-4">
                            {data.upcoming.map((item) => (
                                <div
                                    key={item.name}
                                    className="flex items-center gap-3"
                                >
                                    <span className="grid size-10 shrink-0 place-content-center rounded-md bg-muted text-center text-xs">
                                        <span className="text-[10px] text-muted-foreground">
                                            {data.label.slice(0, 3)}
                                        </span>
                                        <span className="font-semibold">
                                            {item.day}
                                        </span>
                                    </span>
                                    <span className="flex-1 text-sm">
                                        {item.name}
                                    </span>
                                    <span className="text-sm tabular-nums">
                                        {money(item.amount, data.currency)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                    <Separator />
                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="checkbox"
                            checked={extra}
                            onChange={(event) => onExtra(event.target.checked)}
                            className="mt-1 size-4 accent-primary"
                        />
                        <span className="grid gap-1 text-sm">
                            <span className="font-medium">
                                What if I add a weekend away?
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Add {money(data.extra, data.currency)} to this
                                scenario.
                            </span>
                        </span>
                    </label>
                </section>
                <section className="min-w-0 rounded-xl border p-5 sm:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                Simulated month-end spending
                            </p>
                            <p
                                className="mt-2 text-4xl font-semibold tracking-tight tabular-nums"
                                aria-live="polite"
                            >
                                {money(projected, data.currency)}
                            </p>
                        </div>
                        <div className="text-left sm:text-right">
                            <Badge
                                variant={room < 0 ? 'destructive' : 'secondary'}
                            >
                                {money(Math.abs(room), data.currency)}{' '}
                                {room < 0 ? 'over' : 'under'} target
                            </Badge>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {remainingDays} days to shape
                            </p>
                        </div>
                    </div>
                    <div className="my-6">
                        <SpendingLine data={data} projection={projected} />
                    </div>
                    <div className="flex flex-wrap gap-5 text-xs text-muted-foreground">
                        <span className="flex items-center gap-2">
                            <span className="h-0.5 w-4 bg-chart-1" />
                            Recorded example
                        </span>
                        <span className="flex items-center gap-2">
                            <span className="w-4 border-t-2 border-dashed border-chart-2" />
                            Scenario, not a forecast
                        </span>
                    </div>
                    <Separator className="my-5" />
                    <dl className="grid gap-2 text-sm">
                        {[
                            ['Spent through day 10', data.spending],
                            ['Upcoming commitments', commitments],
                            [
                                `Everyday spending · ${remainingDays} × ${money(daily, data.currency)}`,
                                daily * remainingDays,
                            ],
                            ...(extra ? [['Weekend away', data.extra]] : []),
                        ].map(([label, value]) => (
                            <div
                                key={String(label)}
                                className="flex justify-between gap-4"
                            >
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="shrink-0 tabular-nums">
                                    {money(Number(value), data.currency)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            </div>
            <div className="flex max-w-3xl items-start gap-3 text-sm leading-relaxed text-muted-foreground">
                <Info className="mt-0.5 size-4 shrink-0" />
                <p>
                    This is room under a spending target, not money available in
                    your accounts. Commitments and targets are fictional
                    planning inputs. This concept would need you to supply them.
                </p>
            </div>
        </div>
    );
}

export function VariantF({
    data,
    reviewed,
    onReview,
    step,
    onStep,
    focus,
    onFocus,
}: {
    data: Example;
    reviewed: string[];
    onReview: (id: string) => void;
    step: number;
    onStep: (value: number) => void;
    focus: string;
    onFocus: (value: string) => void;
}) {
    const tasks =
        data.currency === 'PEN'
            ? [
                  {
                      id: 'market',
                      merchant: 'Local market',
                      amount: 86,
                      category: 'Groceries',
                  },
                  {
                      id: 'ride',
                      merchant: 'Cabify',
                      amount: 24,
                      category: 'Transport',
                  },
                  {
                      id: 'cafe',
                      merchant: 'Neighbourhood café',
                      amount: 18,
                      category: 'Food & dining',
                  },
              ]
            : [
                  {
                      id: 'market',
                      merchant: 'Digital tools',
                      amount: 15,
                      category: 'Software',
                  },
                  {
                      id: 'ride',
                      merchant: 'Online store',
                      amount: 22,
                      category: 'Shopping',
                  },
                  {
                      id: 'cafe',
                      merchant: 'Streaming service',
                      amount: 8,
                      category: 'Entertainment',
                  },
              ];
    const complete = step === 3;

    return (
        <div className="grid gap-7">
            <header>
                <p className="mb-2 text-xs font-medium tracking-widest text-muted-foreground uppercase">
                    A small money ritual
                </p>
                <h2 className="text-3xl font-semibold tracking-tight">
                    {complete
                        ? 'You have a clearer picture.'
                        : 'Get caught up. Then get on with your day.'}
                </h2>
                <p className="mt-2 text-sm text-muted-foreground">
                    A few useful decisions, with a clear place to stop.
                </p>
            </header>
            <div className="grid gap-7 lg:grid-cols-[minmax(0,1.9fr)_minmax(240px,0.8fr)]">
                <section className="min-w-0 overflow-hidden rounded-xl border">
                    <ol className="grid grid-cols-3 border-b bg-muted/30">
                        {[
                            'Clear the loose ends',
                            'Notice a change',
                            'Choose a focus',
                        ].map((label, index) => (
                            <li key={label}>
                                <button
                                    type="button"
                                    disabled={index > step}
                                    onClick={() => onStep(index)}
                                    aria-current={
                                        step === index ? 'step' : undefined
                                    }
                                    className={cn(
                                        'flex h-full w-full flex-col items-start gap-2 border-b-2 p-3 text-left text-xs focus-visible:ring-2 focus-visible:ring-ring sm:flex-row sm:items-center sm:p-4',
                                        step === index
                                            ? 'border-foreground font-semibold'
                                            : 'border-transparent text-muted-foreground',
                                        index > step && 'opacity-50',
                                    )}
                                >
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-background">
                                        {step > index ? (
                                            <Check className="size-3" />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>
                                    {label}
                                </button>
                            </li>
                        ))}
                    </ol>
                    <div className="p-5 sm:p-7" aria-live="polite">
                        {step === 0 && (
                            <>
                                <div className="mb-6 flex items-start justify-between gap-3">
                                    <div>
                                        <h3 className="text-xl font-semibold">
                                            Give 3 transactions a home.
                                        </h3>
                                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                            The amounts are already included in
                                            net spending. These example
                                            suggestions only change their
                                            category.
                                        </p>
                                    </div>
                                    <Badge variant="secondary">
                                        {reviewed.length}/3
                                    </Badge>
                                </div>
                                <div className="divide-y">
                                    {tasks.map((task) => (
                                        <div
                                            key={task.id}
                                            className="flex flex-wrap items-center justify-between gap-3 py-4"
                                        >
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {task.merchant}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {data.label.slice(0, 3)} 9 ·{' '}
                                                    {money(
                                                        task.amount,
                                                        data.currency,
                                                    )}
                                                </p>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant={
                                                    reviewed.includes(task.id)
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    onReview(task.id)
                                                }
                                            >
                                                {reviewed.includes(task.id) ? (
                                                    <>
                                                        <Check />
                                                        {task.category} · undo
                                                    </>
                                                ) : (
                                                    <>
                                                        Use {task.category}
                                                        <ArrowRight />
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-xs text-muted-foreground">
                                        Sample actions · nothing is saved
                                    </p>
                                    <Button
                                        disabled={reviewed.length < 3}
                                        onClick={() => onStep(1)}
                                    >
                                        Continue
                                        <ArrowRight />
                                    </Button>
                                </div>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <Badge variant="outline">
                                    One thing worth noticing
                                </Badge>
                                <h3 className="mt-5 text-2xl leading-tight font-semibold">
                                    {data.categories[0].name} is up{' '}
                                    {money(
                                        data.categories[0].current -
                                            data.categories[0].previous,
                                        data.currency,
                                    )}
                                    .
                                </h3>
                                <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                    {data.categories[0].reason} This compares
                                    the first ten days of each month.
                                </p>
                                <div className="mt-7 grid gap-4">
                                    {[
                                        {
                                            name: 'Last month',
                                            value: data.categories[0].previous,
                                        },
                                        {
                                            name: 'This month',
                                            value: data.categories[0].current,
                                        },
                                    ].map((item) => (
                                        <div key={item.name}>
                                            <div className="mb-2 flex justify-between text-sm">
                                                <span>{item.name}</span>
                                                <span className="tabular-nums">
                                                    {money(
                                                        item.value,
                                                        data.currency,
                                                    )}
                                                </span>
                                            </div>
                                            <div className="h-5 rounded bg-muted">
                                                <div
                                                    className="h-full rounded bg-chart-1"
                                                    style={{
                                                        width: `${(item.value / Math.max(data.categories[0].current, data.categories[0].previous)) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-8 flex justify-end">
                                    <Button onClick={() => onStep(2)}>
                                        Got it. Choose a focus
                                        <ArrowRight />
                                    </Button>
                                </div>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <h3 className="text-xl font-semibold">
                                    What matters to you this month?
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    One intention is enough. Your Home could
                                    bring it back at the next check-in.
                                </p>
                                <fieldset className="mt-6 grid gap-3">
                                    <legend className="sr-only">
                                        Monthly focus
                                    </legend>
                                    {[
                                        'Keep everyday spending steady',
                                        'Keep moving money to savings',
                                        'Just understand my money',
                                    ].map((label) => (
                                        <label
                                            key={label}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-3 rounded-lg border p-4 text-sm',
                                                focus === label &&
                                                    'border-foreground/30 bg-muted/40',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="monthly-focus"
                                                value={label}
                                                checked={focus === label}
                                                onChange={() => onFocus(label)}
                                                className="size-4 accent-primary"
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </fieldset>
                                <div className="mt-7 flex justify-end">
                                    <Button
                                        disabled={!focus}
                                        onClick={() => onStep(3)}
                                    >
                                        Finish check-in
                                        <Check />
                                    </Button>
                                </div>
                            </>
                        )}
                        {complete && (
                            <div className="flex flex-col items-center gap-4 py-8 text-center">
                                <CircleCheck className="size-12 text-chart-2" />
                                <h3 className="text-2xl font-semibold">
                                    That is enough for today.
                                </h3>
                                <p className="max-w-sm text-sm leading-relaxed text-muted-foreground">
                                    3 categories reviewed. One change
                                    understood. Your focus for{' '}
                                    {data.label.toLowerCase()} is set for this
                                    demo.
                                </p>
                                <div className="rounded-lg border px-5 py-3 text-sm font-medium">
                                    {focus}
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() => onStep(0)}
                                >
                                    Revisit this check-in
                                    <RotateCcw />
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Progress resets on reload.
                                </p>
                            </div>
                        )}
                    </div>
                </section>
                <aside className="flex min-w-0 flex-col gap-6">
                    <section>
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <Clock3 className="size-4" />
                            {complete ? 'Check-in complete' : 'About 3 minutes'}
                        </div>
                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                            {complete
                                ? 'You can stop here. The full details will still be there when you need them.'
                                : 'Review a few details, understand a change, and decide what to pay attention to.'}
                        </p>
                    </section>
                    <Separator />
                    <section>
                        <h3 className="text-sm font-semibold">
                            Your month stays in view
                        </h3>
                        <dl className="mt-4 grid gap-3 text-sm">
                            {[
                                ['Net spending', data.spending],
                                ['Income', data.income],
                                ['Moved to savings', data.savings],
                            ].map(([label, value]) => (
                                <div
                                    key={String(label)}
                                    className="flex justify-between gap-3"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {money(Number(value), data.currency)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mt-4 text-xs text-muted-foreground">
                            {data.transactions} example transactions ·{' '}
                            {data.currency}
                        </p>
                    </section>
                    <Separator />
                    <section>
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <Info className="size-4" />
                            Before trusting the picture
                        </h3>
                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                            Categories can be complete while a statement is
                            still missing. This example includes activity
                            through {data.label} 10. Missing activity can change
                            these totals.
                        </p>
                    </section>
                </aside>
            </div>
        </div>
    );
}

function ExplorationSession({
    data,
    variant,
    actual,
}: {
    data: Example;
    variant: string;
    actual: Briefing | null;
}) {
    const [selected, setSelected] = useState(0);
    const [daily, setDaily] = useState(data.daily);
    const [extra, setExtra] = useState(false);
    const [reviewed, setReviewed] = useState<string[]>([]);
    const [step, setStep] = useState(0);
    const [focus, setFocus] = useState('');

    return (
        <>
            {variant === 'D' && (
                <VariantD
                    data={data}
                    selected={selected}
                    onSelect={setSelected}
                    remaining={3 - reviewed.length}
                />
            )}
            {variant === 'E' && (
                <VariantE
                    data={data}
                    daily={daily}
                    extra={extra}
                    onDaily={setDaily}
                    onExtra={setExtra}
                />
            )}
            {variant === 'F' && (
                <VariantF
                    data={data}
                    reviewed={reviewed}
                    onReview={(id) =>
                        setReviewed((current) =>
                            current.includes(id)
                                ? current.filter((value) => value !== id)
                                : [...current, id],
                        )
                    }
                    step={step}
                    onStep={setStep}
                    focus={focus}
                    onFocus={setFocus}
                />
            )}
            <PrototypeSwitcher
                concepts={concepts}
                state={{
                    dataSource:
                        'Fictional examples, viewed on day 10 of each month',
                    sample: data,
                    actualBriefingAvailable: actual !== null,
                    selectedSignal: selected,
                    daily,
                    extra,
                    projectedSpending:
                        data.spending +
                        data.upcoming.reduce(
                            (total, item) => total + item.amount,
                            0,
                        ) +
                        daily * (data.days - 10) +
                        (extra ? data.extra : 0),
                    reviewed,
                    step,
                    focus,
                    persistence:
                        'None; month or currency changes reset the exercise',
                }}
            />
        </>
    );
}

export default function HomeExplorationPrototype({
    primary,
    secondary,
}: {
    primary: Briefing | null;
    secondary: Briefing | null;
}) {
    const { url } = usePage();
    const params = new URL(url, 'http://localhost').searchParams;
    const variant = params.get('variant') ?? 'D';
    const month = months.includes(params.get('month') ?? '')
        ? params.get('month')!
        : '2026-09';
    const currency: Currency = params.get('currency') === 'USD' ? 'USD' : 'PEN';
    const data = example(month, currency);
    function updateQuery(values: Record<string, string>) {
        Object.entries(values).forEach(([key, value]) =>
            params.set(key, value),
        );
        router.replace({
            url: home.url({ query: Object.fromEntries(params) }),
            preserveState: true,
            preserveScroll: true,
        });
    }

    return (
        <>
            <Head title="Home exploration" />
            <main className="flex min-w-0 flex-1 flex-col gap-7 p-4 pb-40 md:p-6 md:pb-40 xl:px-8">
                <header className="flex flex-wrap items-center justify-between gap-4 border-b pb-4">
                    <div className="flex items-center gap-3">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Home
                        </h1>
                        <Badge variant="outline">
                            <Sparkles className="size-3" />
                            Fictional example
                        </Badge>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Tabs
                            value={currency}
                            onValueChange={(value) =>
                                updateQuery({ currency: String(value) })
                            }
                        >
                            <TabsList aria-label="Currency">
                                <TabsTrigger value="PEN">PEN</TabsTrigger>
                                <TabsTrigger value="USD">USD</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Previous month"
                                disabled={month === months[0]}
                                onClick={() =>
                                    updateQuery({
                                        month: months[
                                            months.indexOf(month) - 1
                                        ],
                                    })
                                }
                            >
                                <ChevronLeft />
                            </Button>
                            <label className="sr-only" htmlFor="example-month">
                                Example month
                            </label>
                            <select
                                id="example-month"
                                value={month}
                                onChange={(event) =>
                                    updateQuery({ month: event.target.value })
                                }
                                className="max-w-40 rounded-md bg-background p-2 text-sm font-medium outline-offset-2"
                            >
                                {months.map((value, index) => (
                                    <option key={value} value={value}>
                                        {monthNames[index]} 2026
                                    </option>
                                ))}
                            </select>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Next month"
                                disabled={month === months[months.length - 1]}
                                onClick={() =>
                                    updateQuery({
                                        month: months[
                                            months.indexOf(month) + 1
                                        ],
                                    })
                                }
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>
                </header>
                <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                        As of {data.label} 10 · {data.transactions} example
                        transactions · {currency} only
                    </span>
                    <span>Explore freely. Changes stay in this demo.</span>
                </div>
                <ExplorationSession
                    key={`${month}-${currency}`}
                    data={data}
                    variant={variant}
                    actual={currency === 'PEN' ? primary : secondary}
                />
            </main>
        </>
    );
}
