// THROWAWAY: three Home concepts on the existing route, switchable via ?variant=A|B|C.
// Question: should Home lead with a briefing, relative money totals, or an attention workspace?
// Sample months and review actions are in memory. No financial records are changed.
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    FileCheck2,
    ListChecks,
    Mail,
    PiggyBank,
    ReceiptText,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { PrototypeSwitcher } from '@/components/prototype-switcher';
import { SourceCoverage } from '@/components/source-coverage';
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
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    categoryBreakdownUrl,
    periodBreakdownUrl,
} from '@/lib/transaction-filter-url';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { create as createStatementImport } from '@/routes/statement_imports';
import type { Currency } from '@/types';
import type { Briefing } from './home';

type Focus = 'net_spending' | 'income' | 'savings';
type WorkspaceTask = 'review' | 'change' | 'coverage';
const metrics = [
    {
        key: 'net_spending',
        field: 'net_spending_minor',
        label: 'Net Spending',
        icon: ArrowUpRight,
        color: 'var(--chart-1)',
        explanation: 'Spending minus refunds and reimbursements.',
    },
    {
        key: 'income',
        field: 'income_minor',
        label: 'Income',
        icon: ArrowDownLeft,
        color: 'var(--chart-2)',
        explanation:
            'Money recorded as income. Refunds and transfers stay separate.',
    },
    {
        key: 'savings',
        field: 'moved_to_savings_minor',
        label: 'Moved to Savings',
        icon: PiggyBank,
        color: 'var(--chart-3)',
        explanation: 'Transfers to savings minus withdrawals from savings.',
    },
] as const;
const sampleMonths = ['2026-06', '2026-07', '2026-08', '2026-09'];
const sampleNumbers = [
    [412800, 680000, 90000, 38800, 52000, 2, 78],
    [465200, 680000, 100000, 72100, 52000, 5, 91],
    [389400, 720000, 140000, 45600, 54000, 0, 86],
    [184650, 720000, 120000, 62800, 38400, 3, 42],
];

function monthLabel(month: string) {
    return new Intl.DateTimeFormat('en', {
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${month}-01T12:00:00`));
}

function sampleBriefing(month: string, currency: Currency): Briefing {
    const index = Math.max(0, sampleMonths.indexOf(month));
    const [spending, income, savings, category, typical, count, transactions] =
        sampleNumbers[index];
    const amount = (value: number) =>
        String(Math.round(value * (currency === 'USD' ? 0.13 : 1)));
    const to = `${month}-${month === '2026-09' ? '09' : new Date(2026, Number(month.slice(5)), 0).getDate()}`;

    return {
        currency,
        period: {
            label: monthLabel(month),
            date_from: `${month}-01`,
            date_to: to,
        },
        coverage: {
            date_from: `${month}-01`,
            date_to: to,
            transaction_count: transactions,
            source: {
                status: 'recorded',
                gmail_last_checked_at: null,
                verified_periods: [],
            },
        },
        summary: {
            net_spending_minor: amount(spending),
            income_minor: amount(income),
            moved_to_savings_minor: amount(savings),
        },
        material_change: {
            category: { id: null, name: 'Food & dining' },
            current_total_minor: amount(category),
            typical_total_minor: amount(typical),
            change_minor: amount(category - typical),
            comparison_periods: [1, 2, 3].map((offset) => {
                const date = new Date(
                    2026,
                    Number(month.slice(5)) - 1 - offset,
                    1,
                    12,
                );
                const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

                return {
                    label: monthLabel(key),
                    date_from: `${key}-01`,
                    date_to: `${key}-${month === '2026-09' ? '09' : new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()}`,
                };
            }),
        },
        input_request: count > 0 ? { transaction_count: count } : null,
    };
}

type ConceptProps = {
    briefing: Briefing;
    sample: boolean;
    focus: Focus;
    onFocus: (focus: Focus) => void;
    reviewed: boolean;
    onReview: () => void;
    task: WorkspaceTask;
    onTask: (task: WorkspaceTask) => void;
};

function DetailLink({
    briefing,
    sample,
    focus,
    attention,
    children,
}: {
    briefing: Briefing;
    sample: boolean;
    focus?: Focus;
    attention?: boolean;
    children: React.ReactNode;
}) {
    return sample ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <ReceiptText className="size-3.5" />
            Sample evidence · explore with your briefing for real Transactions
        </p>
    ) : (
        <Button asChild size="sm" variant="outline">
            <Link
                href={periodBreakdownUrl({
                    currency: briefing.currency,
                    period: briefing.period,
                    focus,
                    attention,
                })}
            >
                {children}
                <ArrowRight />
            </Link>
        </Button>
    );
}

function Coverage({
    briefing,
    sample,
}: Pick<ConceptProps, 'briefing' | 'sample'>) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center gap-2 text-sm font-medium">
                <FileCheck2 className="size-4 text-muted-foreground" />
                What this covers
            </div>
            <p className="text-sm text-muted-foreground">
                <span className="font-medium text-foreground">
                    {briefing.coverage.transaction_count} Transactions
                </span>{' '}
                recorded from {briefing.coverage.date_from} to{' '}
                {briefing.coverage.date_to}.
            </p>
            {sample ? (
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Mail className="size-3.5" />
                    Illustrative data for comparing the concepts
                </p>
            ) : (
                <SourceCoverage
                    source={briefing.coverage.source}
                    className="flex flex-col gap-2 text-xs text-muted-foreground"
                    gmailMissingLabel="Gmail has not been checked"
                />
            )}
        </div>
    );
}

function Change({
    briefing,
    sample,
}: Pick<ConceptProps, 'briefing' | 'sample'>) {
    const change = briefing.material_change;

    if (!change) {
        return (
            <div className="flex flex-col gap-3 py-6">
                <TrendingUp className="size-6 text-muted-foreground" />
                <h3 className="font-medium">No material change to highlight</h3>
                <p className="text-sm text-muted-foreground">
                    A comparison appears when there is enough history and a
                    meaningful category change.
                </p>
            </div>
        );
    }

    const down = Number(change.change_minor) < 0;
    const maximum = Math.max(
        Math.abs(Number(change.current_total_minor)),
        Math.abs(Number(change.typical_total_minor)),
        1,
    );

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <p className="text-xs font-medium text-muted-foreground">
                    The change worth a look
                </p>
                <h3 className="text-xl font-semibold tracking-tight">
                    {change.category.name}
                </h3>
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {formatMinorUnits(
                            String(Math.abs(Number(change.change_minor))),
                            briefing.currency,
                        )}{' '}
                        {down ? 'less' : 'more'}
                    </span>{' '}
                    than typical for the equivalent period.
                </p>
            </div>
            <div className="flex flex-col gap-4">
                {[
                    {
                        label: 'This period',
                        value: change.current_total_minor,
                        fill: 'var(--chart-1)',
                    },
                    {
                        label: 'Typical',
                        value: change.typical_total_minor,
                        fill: 'var(--muted-foreground)',
                    },
                ].map((row) => (
                    <div key={row.label} className="flex flex-col gap-2">
                        <div className="flex justify-between gap-2 text-xs">
                            <span className="text-muted-foreground">
                                {row.label}
                            </span>
                            <span className="font-medium tabular-nums">
                                {formatMinorUnits(row.value, briefing.currency)}
                            </span>
                        </div>
                        <div className="h-2 rounded-full bg-muted">
                            <div
                                className="h-full rounded-full"
                                style={{
                                    width: `${(Math.abs(Number(row.value)) / maximum) * 100}%`,
                                    backgroundColor: row.fill,
                                }}
                            />
                        </div>
                    </div>
                ))}
            </div>
            <p className="text-xs leading-relaxed text-muted-foreground">
                Typical uses the prior three equivalent periods.{' '}
                {change.comparison_periods
                    .map((period) => period.label)
                    .join(' · ')}
                .
            </p>
            {!sample && (
                <Button asChild variant="outline" size="sm" className="w-fit">
                    <Link
                        href={categoryBreakdownUrl({
                            currency: briefing.currency,
                            period: briefing.period,
                            categoryId: change.category.id,
                        })}
                    >
                        Explore the change
                        <ArrowRight />
                    </Link>
                </Button>
            )}
        </div>
    );
}

function Review({
    briefing,
    sample,
    reviewed,
    onReview,
}: Pick<ConceptProps, 'briefing' | 'sample' | 'reviewed' | 'onReview'>) {
    const count = reviewed
        ? 0
        : (briefing.input_request?.transaction_count ?? 0);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-2">
                <span className="flex size-9 items-center justify-center rounded-full bg-muted">
                    {count ? (
                        <CircleAlert className="size-4" />
                    ) : (
                        <Check className="size-4" />
                    )}
                </span>
                <Badge variant="secondary">
                    {count ? `${count} to review` : 'Up to date'}
                </Badge>
            </div>
            <div className="flex flex-col gap-2">
                <h3 className="text-lg font-semibold">
                    {count
                        ? 'Review the details behind your totals.'
                        : 'Nothing needs your input.'}
                </h3>
                <p className="text-sm leading-relaxed text-muted-foreground">
                    {count
                        ? 'These Transactions are already included in your totals. Reviewing their details makes the picture more reliable.'
                        : reviewed
                          ? 'Sample review complete. Your financial records have not changed.'
                          : 'There are no flagged details to review in this briefing.'}
                </p>
            </div>
            {count > 0 &&
                (sample ? (
                    <Button onClick={onReview} className="w-fit">
                        Try completing the review
                        <Check />
                    </Button>
                ) : (
                    <DetailLink briefing={briefing} sample={false} attention>
                        Review Transactions
                    </DetailLink>
                ))}
            {sample && reviewed && (
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={onReview}
                    className="w-fit"
                >
                    Reset sample review
                </Button>
            )}
        </div>
    );
}

export function VariantA(props: ConceptProps) {
    const { briefing, sample } = props;

    return (
        <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_19rem]">
            <div className="flex min-w-0 flex-col gap-7">
                <section className="flex flex-col gap-5 border-b pb-7">
                    <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        Your monthly briefing
                    </p>
                    <div className="flex flex-col gap-2">
                        <h2 className="text-lg text-muted-foreground">
                            Net Spending this period
                        </h2>
                        <p className="text-5xl font-semibold tracking-tight tabular-nums sm:text-6xl">
                            {formatMinorUnits(
                                briefing.summary.net_spending_minor,
                                briefing.currency,
                            )}
                        </p>
                    </div>
                    <p className="max-w-lg text-sm leading-relaxed text-muted-foreground">
                        The month at a glance, with one change to understand and
                        the details that need your input.
                    </p>
                    <div>
                        <DetailLink
                            briefing={briefing}
                            sample={sample}
                            focus="net_spending"
                        >
                            Explore Net Spending
                        </DetailLink>
                    </div>
                </section>
                <div className="grid grid-cols-2 gap-6">
                    {metrics.slice(1).map((metric) => (
                        <div key={metric.key} className="flex flex-col gap-2">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <metric.icon className="size-4" />
                                {metric.label}
                            </div>
                            <p className="text-2xl font-semibold tracking-tight tabular-nums">
                                {formatMinorUnits(
                                    briefing.summary[metric.field],
                                    briefing.currency,
                                )}
                            </p>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                {metric.explanation}
                            </p>
                        </div>
                    ))}
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>What changed</CardTitle>
                        <CardDescription>
                            A closer look at one category, without opening a
                            full report.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Change {...props} />
                    </CardContent>
                </Card>
            </div>
            <aside className="flex flex-col gap-6 xl:border-l xl:pl-7">
                <Review {...props} />
                <Separator />
                <Coverage {...props} />
                <Separator />
                <p className="text-xs leading-relaxed text-muted-foreground">
                    Each currency has its own briefing. Switch above to see the
                    other currency.
                </p>
            </aside>
        </div>
    );
}

export function VariantB(props: ConceptProps) {
    const { briefing, focus, onFocus, sample } = props;
    const metric = metrics.find((item) => item.key === focus)!;
    const config = { amount: { label: metric.label, color: metric.color } };
    const chartData = metrics.map((item) => ({
        name: item.label,
        amount: Number(briefing.summary[item.field]) / 100,
        fill: item.color,
    }));

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-col gap-2">
                <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                    The big picture
                </p>
                <h2 className="text-3xl font-semibold tracking-tight">
                    Three views of your money.
                </h2>
                <p className="text-sm text-muted-foreground">
                    Choose a total to understand what it includes.
                </p>
            </header>
            <div className="grid gap-0 overflow-hidden rounded-xl border lg:grid-cols-[18rem_minmax(0,1fr)]">
                <Tabs
                    value={focus}
                    onValueChange={(value) => onFocus(value as Focus)}
                    orientation="vertical"
                    className="border-b bg-muted/30 lg:border-r lg:border-b-0"
                >
                    <TabsList
                        variant="line"
                        className="h-auto w-full flex-col items-stretch gap-0 p-3"
                    >
                        {metrics.map((item) => (
                            <TabsTrigger
                                value={item.key}
                                key={item.key}
                                className="justify-start px-4 py-5"
                            >
                                <span className="flex flex-col items-start gap-2">
                                    <span className="flex items-center gap-2 text-xs">
                                        <item.icon />
                                        {item.label}
                                    </span>
                                    <span className="text-2xl font-semibold tracking-tight tabular-nums">
                                        {formatMinorUnits(
                                            briefing.summary[item.field],
                                            briefing.currency,
                                        )}
                                    </span>
                                </span>
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
                <div className="flex min-w-0 flex-col gap-4 p-5 sm:p-7">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="font-medium">Relative scale</h3>
                        <Badge variant="outline">{briefing.currency}</Badge>
                    </div>
                    <ChartContainer
                        config={config}
                        className="aspect-auto h-52 w-full"
                    >
                        <BarChart
                            accessibilityLayer
                            data={chartData}
                            layout="vertical"
                            margin={{ left: 0, right: 24 }}
                        >
                            <CartesianGrid horizontal={false} />
                            <XAxis
                                type="number"
                                axisLine={false}
                                tickLine={false}
                                tickFormatter={(value: number) =>
                                    new Intl.NumberFormat('en', {
                                        notation: 'compact',
                                    }).format(value)
                                }
                            />
                            <YAxis
                                type="category"
                                dataKey="name"
                                width={110}
                                axisLine={false}
                                tickLine={false}
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        config={config}
                                        formatValue={(value) =>
                                            formatMinorUnits(
                                                String(
                                                    Math.round(
                                                        Number(value) * 100,
                                                    ),
                                                ),
                                                briefing.currency,
                                            )
                                        }
                                    />
                                }
                            />
                            <Bar
                                dataKey="amount"
                                name="Total"
                                isAnimationActive={false}
                                radius={[0, 5, 5, 0]}
                                maxBarSize={32}
                            />
                        </BarChart>
                    </ChartContainer>
                    <Separator />
                    <div className="flex flex-col gap-2" aria-live="polite">
                        <p className="flex items-center gap-2 text-sm font-semibold">
                            <metric.icon className="size-4" />
                            {metric.label}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {metric.explanation}
                        </p>
                        <div className="mt-2">
                            <DetailLink
                                briefing={briefing}
                                sample={sample}
                                focus={focus}
                            >
                                See {metric.label} Transactions
                            </DetailLink>
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        These are separate measures for recorded activity, not
                        an account balance.
                    </p>
                </div>
            </div>
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16rem]">
                <section className="rounded-xl border p-5">
                    <Change {...props} />
                </section>
                <section className="rounded-xl border p-5">
                    <Review {...props} />
                </section>
                <aside className="py-2">
                    <Coverage {...props} />
                </aside>
            </div>
        </div>
    );
}

export function VariantC(props: ConceptProps) {
    const { briefing, task, onTask, reviewed } = props;
    const count = reviewed
        ? 0
        : (briefing.input_request?.transaction_count ?? 0);
    const tasks = [
        {
            key: 'review',
            label: 'Review details',
            detail: count
                ? `${count} Transactions need input`
                : 'Nothing waiting',
            icon: ListChecks,
        },
        {
            key: 'change',
            label: 'Understand a change',
            detail:
                briefing.material_change?.category.name ??
                'No change highlighted',
            icon: TrendingUp,
        },
        {
            key: 'coverage',
            label: 'Check coverage',
            detail: `${briefing.coverage.transaction_count} recorded Transactions`,
            icon: FileCheck2,
        },
    ] as const;

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex flex-col gap-2">
                    <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        Your money workspace
                    </p>
                    <h2 className="text-3xl font-semibold tracking-tight">
                        A few things worth your time.
                    </h2>
                </div>
                <Badge variant="outline">
                    {count ? 'Input needed' : 'Review complete'}
                </Badge>
            </header>
            <div className="grid min-h-96 overflow-hidden rounded-xl border lg:grid-cols-[19rem_minmax(0,1fr)]">
                <nav
                    aria-label="Briefing tasks"
                    className="flex flex-col gap-2 border-b bg-muted/30 p-3 lg:border-r lg:border-b-0"
                >
                    {tasks.map((item, index) => (
                        <button
                            key={item.key}
                            onClick={() => onTask(item.key)}
                            aria-pressed={task === item.key}
                            className={cn(
                                'flex items-start gap-3 rounded-lg p-4 text-left transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                task === item.key &&
                                    'bg-background shadow-sm ring-1 ring-border',
                            )}
                        >
                            <span className="pt-0.5 text-xs text-muted-foreground">
                                0{index + 1}
                            </span>
                            <span className="flex flex-1 flex-col gap-1">
                                <span className="text-sm font-medium">
                                    {item.label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {item.detail}
                                </span>
                            </span>
                            <item.icon className="mt-0.5 size-4 text-muted-foreground" />
                        </button>
                    ))}
                    <p className="mt-auto p-4 text-xs leading-relaxed text-muted-foreground">
                        Choose one item. The supporting context stays here while
                        you work.
                    </p>
                </nav>
                <section
                    className="flex flex-col justify-center p-6 sm:p-10"
                    aria-live="polite"
                >
                    {task === 'review' && (
                        <div className="flex max-w-xl flex-col gap-6">
                            <Review {...props} />
                            {count > 0 && (
                                <div className="grid grid-cols-2 gap-4 rounded-lg bg-muted/50 p-4">
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Needs input
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {count}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Recorded in this period
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                                            {
                                                briefing.coverage
                                                    .transaction_count
                                            }
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                    {task === 'change' && (
                        <div className="max-w-xl">
                            <Change {...props} />
                        </div>
                    )}
                    {task === 'coverage' && (
                        <div className="flex max-w-xl flex-col gap-6">
                            <h3 className="text-2xl font-semibold tracking-tight">
                                Know what is in the picture.
                            </h3>
                            <Coverage {...props} />
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                Statement verification and Gmail checks help you
                                judge how complete the recorded activity is.
                            </p>
                            {!props.sample && (
                                <Button
                                    asChild
                                    className="w-fit"
                                    variant="outline"
                                >
                                    <Link href={createStatementImport()}>
                                        Import a statement
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            )}
                        </div>
                    )}
                </section>
            </div>
            <section className="flex flex-col gap-4">
                <h3 className="text-sm font-medium">
                    The numbers, close at hand
                </h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    {metrics.map((metric) => (
                        <div
                            key={metric.key}
                            className="flex items-start gap-3 border-t pt-4"
                        >
                            <metric.icon className="mt-1 size-4 text-muted-foreground" />
                            <div className="flex flex-col gap-1">
                                <p className="text-xs text-muted-foreground">
                                    {metric.label}
                                </p>
                                <p className="text-2xl font-semibold tracking-tight tabular-nums">
                                    {formatMinorUnits(
                                        briefing.summary[metric.field],
                                        briefing.currency,
                                    )}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}

export default function HomePrototype({
    primary,
    secondary,
}: {
    primary: Briefing | null;
    secondary: Briefing | null;
}) {
    const { url } = usePage();
    const params = new URL(url, 'http://localhost').searchParams;
    const variant = params.get('variant') ?? 'A';
    const sample = params.get('data') !== 'live';
    const month = sampleMonths.includes(params.get('month') ?? '')
        ? params.get('month')!
        : '2026-09';
    const currency: Currency = params.get('currency') === 'USD' ? 'USD' : 'PEN';
    const [focus, setFocus] = useState<Focus>('net_spending');
    const [task, setTask] = useState<WorkspaceTask>('review');
    const [reviewedPeriods, setReviewedPeriods] = useState<string[]>([]);
    const [monthOpen, setMonthOpen] = useState(false);
    const briefing = sample
        ? sampleBriefing(month, currency)
        : ([primary, secondary].find((item) => item?.currency === currency) ??
          null);
    const reviewKey = `${month}:${currency}`;
    const reviewed = sample && reviewedPeriods.includes(reviewKey);

    function updateQuery(changes: Record<string, string>) {
        Object.entries(changes).forEach(([key, value]) =>
            params.set(key, value),
        );
        router.replace({
            url: home.url({ query: Object.fromEntries(params) }),
            preserveState: true,
            preserveScroll: true,
        });
    }

    const conceptProps = briefing
        ? {
              briefing,
              sample,
              focus,
              onFocus: setFocus,
              task,
              onTask: setTask,
              reviewed,
              onReview: () =>
                  setReviewedPeriods((current) =>
                      reviewed
                          ? current.filter((key) => key !== reviewKey)
                          : [...current, reviewKey],
                  ),
          }
        : null;

    return (
        <>
            <Head title="Home concepts" />
            <main className="flex min-w-0 flex-1 flex-col gap-7 p-4 pb-36 md:p-6 md:pb-36">
                <header className="flex flex-col gap-4 border-b pb-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Home
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <Tabs
                                value={sample ? 'sample' : 'live'}
                                onValueChange={(value) =>
                                    updateQuery({ data: String(value) })
                                }
                            >
                                <TabsList aria-label="Prototype data">
                                    <TabsTrigger value="sample">
                                        Sample months
                                    </TabsTrigger>
                                    <TabsTrigger value="live">
                                        Your briefing
                                    </TabsTrigger>
                                </TabsList>
                            </Tabs>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
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
                        {sample ? (
                            <div className="flex items-center gap-1">
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Previous month"
                                    disabled={month === sampleMonths[0]}
                                    onClick={() =>
                                        updateQuery({
                                            month: sampleMonths[
                                                sampleMonths.indexOf(month) - 1
                                            ],
                                        })
                                    }
                                >
                                    <ChevronLeft />
                                </Button>
                                <Popover
                                    open={monthOpen}
                                    onOpenChange={setMonthOpen}
                                >
                                    <PopoverTrigger
                                        render={
                                            <Button
                                                variant="ghost"
                                                className="min-w-40"
                                            />
                                        }
                                    >
                                        <CalendarDays />
                                        {monthLabel(month)}
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-56"
                                    >
                                        <p className="px-2 pt-1 text-xs text-muted-foreground">
                                            Sample months · 2026
                                        </p>
                                        {sampleMonths.map((value) => (
                                            <Button
                                                key={value}
                                                variant={
                                                    month === value
                                                        ? 'secondary'
                                                        : 'ghost'
                                                }
                                                className="justify-start"
                                                onClick={() => {
                                                    updateQuery({
                                                        month: value,
                                                    });
                                                    setMonthOpen(false);
                                                }}
                                            >
                                                {monthLabel(value)}
                                                {value === month && <Check />}
                                            </Button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Next month"
                                    disabled={month === sampleMonths.at(-1)}
                                    onClick={() =>
                                        updateQuery({
                                            month: sampleMonths[
                                                sampleMonths.indexOf(month) + 1
                                            ],
                                        })
                                    }
                                >
                                    <ChevronRight />
                                </Button>
                            </div>
                        ) : (
                            <div className="flex items-center gap-2 text-sm">
                                <CalendarDays className="size-4 text-muted-foreground" />
                                {briefing?.period.label ?? 'Latest briefing'}
                                <Badge variant="outline">Recorded period</Badge>
                            </div>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {sample
                            ? 'Prototype · illustrative amounts and sample review actions. Nothing is saved.'
                            : 'Prototype · your actual latest-period briefing. Historical month navigation is available in sample mode.'}
                    </p>
                </header>
                {conceptProps ? (
                    variant === 'B' ? (
                        <VariantB {...conceptProps} />
                    ) : variant === 'C' ? (
                        <VariantC {...conceptProps} />
                    ) : (
                        <VariantA {...conceptProps} />
                    )
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>No {currency} briefing yet</CardTitle>
                            <CardDescription>
                                Try sample months to compare the concepts, or
                                import a statement to see your recorded
                                activity.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild>
                                <Link href={createStatementImport()}>
                                    Import a statement
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </main>
            <PrototypeSwitcher
                state={{
                    data: sample ? 'sample' : 'live',
                    period: briefing?.period ?? null,
                    currency,
                    focus,
                    task,
                    reviewed,
                    briefing,
                }}
            />
        </>
    );
}
