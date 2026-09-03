import { Link, router } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Rectangle,
    ReferenceLine,
    XAxis,
} from 'recharts';
import type { BarShapeProps, RectangleProps } from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { formatMinorUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';
import { selectionUrl } from './links';
import type {
    BreakdownCategoryGroup,
    BreakdownDay,
    BreakdownPeriod,
    BreakdownProps,
} from './types';

const currencies = ['PEN', 'USD'] satisfies Currency[];
const dailyChartConfig = {
    PEN: { label: 'PEN', color: 'var(--chart-1)' },
    USD: { label: 'USD', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function visibleCurrencies(currencyFilter: Currency | null): Currency[] {
    return currencyFilter === null ? currencies : [currencyFilter];
}

function categoryKey(categoryId: number | null): string {
    return categoryId === null ? 'uncategorized' : categoryId.toString();
}

export function CategoryBreakdown({
    currencyFilter,
    period,
    groups,
    filters,
}: {
    currencyFilter: Currency | null;
    period: BreakdownPeriod;
    groups: BreakdownCategoryGroup[];
    filters: BreakdownProps['filters'];
}) {
    return (
        <section className="grid min-w-0 content-start gap-3">
            <div>
                <h2 className="font-semibold">Where the money went</h2>
                <p className="text-sm text-muted-foreground">
                    Choose a category to filter the transaction list.
                </p>
            </div>
            {groups.length === 0 ? (
                <p className="border-y py-6 text-center text-sm text-muted-foreground">
                    No category spending in this selection.
                </p>
            ) : (
                <ol className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 border-y">
                    {groups.map((group) => {
                        const key = categoryKey(group.category.id);
                        const selected = filters.category === key;

                        return (
                            <li
                                key={key}
                                className="col-span-2 grid grid-cols-subgrid border-b last:border-b-0"
                            >
                                <Link
                                    href={selectionUrl({
                                        currencyFilter,
                                        period,
                                        category: selected ? null : key,
                                        day: filters.day,
                                        focus: filters.focus,
                                        merchant: filters.merchant,
                                        attention: filters.attention,
                                        selected: null,
                                    })}
                                    preserveScroll
                                    data-test={`breakdown-category-${key}`}
                                    className={`col-span-2 grid grid-cols-subgrid gap-y-2 px-1 py-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden ${selected ? 'bg-primary/5' : ''}`}
                                >
                                    <span className="col-span-2 text-sm font-medium">
                                        {group.category.name}
                                    </span>
                                    <span className="col-span-2 grid grid-cols-subgrid gap-y-1.5">
                                        {visibleCurrencies(currencyFilter)
                                            .filter(
                                                (currency) =>
                                                    group.amount_minor[
                                                        currency
                                                    ] !== '0',
                                            )
                                            .map((currency) => (
                                                <span
                                                    key={currency}
                                                    className="col-span-2 grid grid-cols-subgrid items-center"
                                                >
                                                    <span
                                                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                                                        data-test={`breakdown-category-bar-${key}-${currency}`}
                                                    >
                                                        <span
                                                            className={`block h-full rounded-full ${currency === 'PEN' ? 'bg-chart-1' : 'bg-chart-2'}`}
                                                            style={{
                                                                width: `${Math.min(100, Math.abs(Number(group.percentage[currency])))}%`,
                                                            }}
                                                        />
                                                    </span>
                                                    <span className="text-right text-sm font-semibold whitespace-nowrap tabular-nums">
                                                        {formatMinorUnits(
                                                            group.amount_minor[
                                                                currency
                                                            ],
                                                            currency,
                                                        )}
                                                    </span>
                                                </span>
                                            ))}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function selectedDate(value: unknown): string | null {
    if (
        typeof value === 'object' &&
        value !== null &&
        'date' in value &&
        typeof value.date === 'string'
    ) {
        return value.date;
    }

    if (typeof value === 'object' && value !== null && 'payload' in value) {
        return selectedDate(value.payload);
    }

    return null;
}

function selectedAmount(value: unknown, currency: Currency): number {
    if (typeof value !== 'object' || value === null) {
        return 0;
    }

    if (currency === 'PEN' && 'PEN' in value && typeof value.PEN === 'number') {
        return value.PEN;
    }

    if (currency === 'USD' && 'USD' in value && typeof value.USD === 'number') {
        return value.USD;
    }

    return 0;
}

function DailyBar({
    bar,
    currency,
    selectedDay,
    selectable,
    onSelect,
}: {
    bar: BarShapeProps;
    currency: Currency;
    selectedDay: string | null;
    selectable: boolean;
    onSelect: (date: string) => void;
}) {
    const date = selectedDate(bar.payload);
    const isNegative = bar.height < 0;
    const height = Math.abs(bar.height);
    const y = isNegative ? bar.y + bar.height : bar.y;
    const otherCurrency = currency === 'PEN' ? 'USD' : 'PEN';
    const value = selectedAmount(bar.payload, currency);
    const otherValue = selectedAmount(bar.payload, otherCurrency);
    const isStacked =
        value !== 0 &&
        otherValue !== 0 &&
        Math.sign(value) === Math.sign(otherValue);
    const stackEdge = !isStacked
        ? 'single'
        : (currency === 'PEN') === isNegative
          ? 'top'
          : 'bottom';
    const radius: NonNullable<RectangleProps['radius']> =
        stackEdge === 'single'
            ? [4, 4, 4, 4]
            : stackEdge === 'top'
              ? [4, 4, 0, 0]
              : [0, 0, 4, 4];

    return (
        <Rectangle
            x={bar.x}
            y={y}
            width={bar.width}
            height={height}
            radius={radius}
            fill={`var(--color-${currency})`}
            opacity={selectedDay === null || selectedDay === date ? 1 : 0.35}
            className={
                selectable
                    ? 'cursor-pointer outline-none focus-visible:stroke-ring focus-visible:stroke-2'
                    : undefined
            }
            role={selectable ? 'button' : undefined}
            tabIndex={selectable ? 0 : undefined}
            aria-label={
                !selectable || date === null
                    ? undefined
                    : `Filter transactions on ${date}`
            }
            data-test={
                !selectable || date === null
                    ? undefined
                    : `breakdown-day-${date}`
            }
            data-direction={isNegative ? 'negative' : 'positive'}
            data-currency={currency}
            data-stack-edge={stackEdge}
            onClick={() => {
                if (selectable && date !== null) {
                    onSelect(date);
                }
            }}
            onKeyDown={(event) => {
                if (
                    selectable &&
                    date !== null &&
                    (event.key === 'Enter' || event.key === ' ')
                ) {
                    event.preventDefault();
                    onSelect(date);
                }
            }}
        />
    );
}

export function DailyChart({
    currencyFilter,
    period,
    days,
    granularity,
    filters,
}: {
    currencyFilter: Currency | null;
    period: BreakdownPeriod;
    days: BreakdownDay[];
    granularity: BreakdownProps['chart_granularity'];
    filters: BreakdownProps['filters'];
}) {
    const data = days.map((day) => ({
        date: day.date,
        label: day.date,
        tooltipLabel:
            day.date === day.date_to
                ? formatChartDate(day.date, {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                  })
                : `${formatChartDate(day.date, { month: 'short', day: 'numeric' })} – ${formatChartDate(day.date_to, { month: 'short', day: 'numeric', year: 'numeric' })}`,
        PEN: Number(day.net_spending_minor.PEN) / 100,
        USD: Number(day.net_spending_minor.USD) / 100,
    }));
    const visibleValues = data.flatMap((bucket) =>
        visibleCurrencies(currencyFilter).map((currency) => bucket[currency]),
    );
    const crossesZero =
        visibleValues.some((value) => value > 0) &&
        visibleValues.some((value) => value < 0);

    function filterDate(date: string) {
        router.visit(
            selectionUrl({
                currencyFilter,
                period,
                category: filters.category,
                day: filters.day === date ? null : date,
                focus: filters.focus,
                merchant: filters.merchant,
                attention: filters.attention,
                selected: null,
            }),
            { preserveScroll: true },
        );
    }

    return (
        <section className="grid min-w-0 shrink-0 grid-cols-[minmax(0,1fr)] gap-3">
            <div>
                <h2 className="font-semibold">Spending over time</h2>
                <p className="text-sm text-muted-foreground">
                    {granularity === 'day'
                        ? 'Each bar is one day. Select one to filter transactions.'
                        : `Each bar is one ${granularity}.`}
                </p>
            </div>
            {data.length === 0 ? (
                <p className="border-y py-6 text-center text-sm text-muted-foreground">
                    No daily spending in this selection.
                </p>
            ) : (
                <ChartContainer
                    config={dailyChartConfig}
                    className="h-52 w-full max-w-full min-w-0"
                >
                    <BarChart data={data} accessibilityLayer>
                        <CartesianGrid vertical={false} />
                        {crossesZero && (
                            <ReferenceLine
                                y={0}
                                className="spending-zero-baseline"
                                stroke="var(--foreground)"
                                strokeOpacity={0.5}
                                strokeWidth={1.5}
                            />
                        )}
                        <XAxis
                            dataKey="label"
                            tickFormatter={(date: string) =>
                                granularity === 'month'
                                    ? formatChartDate(date, { month: 'short' })
                                    : formatChartDate(date, {
                                          month: 'short',
                                          day: 'numeric',
                                      })
                            }
                            tickLine={false}
                            axisLine={false}
                            interval="preserveStartEnd"
                            minTickGap={18}
                        />
                        <ChartTooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.35 }}
                            content={
                                <ChartTooltipContent
                                    config={dailyChartConfig}
                                    formatLabel={(label) =>
                                        data.find(
                                            (bucket) =>
                                                bucket.label === String(label),
                                        )?.tooltipLabel ?? String(label)
                                    }
                                    formatValue={(value, name) =>
                                        formatMinorUnits(
                                            String(
                                                Math.round(Number(value) * 100),
                                            ),
                                            name === 'USD' ? 'USD' : 'PEN',
                                        )
                                    }
                                    dataTest="daily-chart-tooltip"
                                />
                            }
                        />
                        {currencyFilter === null && (
                            <ChartLegend
                                content={
                                    <ChartLegendContent
                                        config={dailyChartConfig}
                                    />
                                }
                            />
                        )}
                        {visibleCurrencies(currencyFilter).map((currency) => (
                            <Bar
                                key={currency}
                                dataKey={currency}
                                stackId={
                                    currencyFilter === null
                                        ? 'spending'
                                        : undefined
                                }
                                fill={`var(--color-${currency})`}
                                radius={[4, 4, 0, 0]}
                                shape={(bar) => (
                                    <DailyBar
                                        bar={bar}
                                        currency={currency}
                                        selectedDay={filters.day}
                                        selectable={granularity === 'day'}
                                        onSelect={filterDate}
                                    />
                                )}
                            />
                        ))}
                    </BarChart>
                </ChartContainer>
            )}
        </section>
    );
}

function formatChartDate(
    date: string,
    options: Intl.DateTimeFormatOptions,
): string {
    return new Intl.DateTimeFormat(undefined, {
        ...options,
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}
