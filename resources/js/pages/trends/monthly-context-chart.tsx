import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ReferenceLine,
    XAxis,
} from 'recharts';
import { DateText } from '@/components/date-time';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    formatCompactMonthYear,
    isLastDayOfMonth,
} from '@/lib/date-presentation';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { cn } from '@/lib/utils';
import type { MonthlyContext, TrendReport } from './types';

const chartConfig = {
    PEN: { label: 'Soles', color: 'var(--chart-1)' },
    USD: { label: 'USD', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function isPartialMonth(month: MonthlyContext): boolean {
    return !isLastDayOfMonth(month.date_to);
}

export function MonthlyContextChart({
    reports,
    className,
}: {
    reports: TrendReport[];
    className?: string;
}) {
    const currencies = reports.map((report) => report.currency);
    const contextMonths = Array.from(
        new Map(
            reports
                .flatMap((report) => report.monthly_context)
                .map((month) => [month.month, month]),
        ).values(),
    ).sort((left, right) => left.month.localeCompare(right.month));
    const monthlyContextByCurrency = new Map(
        reports.map((report) => [
            report.currency,
            new Map(
                report.monthly_context.map((month) => [month.month, month]),
            ),
        ]),
    );
    const chartData = contextMonths.map((month) => ({
        ...month,
        shortLabel: formatCompactMonthYear(month.date_from).split(' ')[0],
        ...Object.fromEntries(
            currencies.map((currency) => {
                const total = monthlyContextByCurrency
                    .get(currency)
                    ?.get(month.month)?.total_minor;

                return [
                    currency,
                    total === null || total === undefined
                        ? null
                        : Number(total) / 100,
                ];
            }),
        ),
    }));
    const contextMonth = contextMonths.at(-1);
    const partialMonth =
        contextMonth !== undefined && isPartialMonth(contextMonth);
    const missingActivity = reports.map((report) => ({
        currency: report.currency,
        months: contextMonths
            .filter(
                (month) =>
                    monthlyContextByCurrency
                        .get(report.currency)
                        ?.get(month.month)?.total_minor == null,
            )
            .map((month) => formatCompactMonthYear(month.date_from)),
    }));

    return (
        <section
            className={cn(
                'flex min-h-0 min-w-0 flex-1 flex-col gap-3 overflow-hidden',
                className,
            )}
            data-test="trends-monthly-context"
        >
            <div className="shrink-0">
                <h2 className="font-semibold">Monthly context</h2>
                <p className="text-sm text-muted-foreground">
                    Previous six months and the current month.
                </p>
            </div>

            {contextMonths.length === 0 ? (
                <p className="border-y py-6 text-center text-sm text-muted-foreground">
                    No recorded activity.
                </p>
            ) : (
                <div className="flex min-h-0 flex-1 flex-col gap-3">
                    <ChartContainer
                        config={chartConfig}
                        className="h-52 w-full max-w-full min-w-0 xl:h-full xl:min-h-40 xl:flex-1"
                        role="img"
                        aria-label={`Seven-month Net Spending context in ${currencies.join(' and ')}`}
                        data-stacked={currencies.length > 1 ? 'true' : 'false'}
                    >
                        <BarChart
                            accessibilityLayer
                            data={chartData}
                            margin={{ left: 0, right: 0, top: 8, bottom: 0 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="shortLabel"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                            />
                            <ReferenceLine y={0} stroke="var(--border)" />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        config={chartConfig}
                                        formatValue={(value, name) =>
                                            formatMinorUnits(
                                                String(
                                                    Math.round(
                                                        Number(value) * 100,
                                                    ),
                                                ),
                                                name === 'USD' ? 'USD' : 'PEN',
                                            )
                                        }
                                    />
                                }
                            />
                            {currencies.length > 1 && (
                                <ChartLegend
                                    content={
                                        <ChartLegendContent
                                            config={chartConfig}
                                        />
                                    }
                                />
                            )}
                            {currencies.map((currency) => (
                                <Bar
                                    key={currency}
                                    dataKey={currency}
                                    name={currency}
                                    stackId={
                                        currencies.length > 1
                                            ? 'spending'
                                            : undefined
                                    }
                                    fill={`var(--color-${currency})`}
                                    radius={[3, 3, 3, 3]}
                                    maxBarSize={36}
                                    minPointSize={2}
                                    isAnimationActive={false}
                                >
                                    {contextMonths.map((month) => (
                                        <Cell
                                            key={`${currency}-${month.month}`}
                                            fill={`var(--color-${currency})`}
                                            fillOpacity={
                                                partialMonth &&
                                                month.month ===
                                                    contextMonth.month
                                                    ? 0.55
                                                    : 1
                                            }
                                            stroke={
                                                partialMonth &&
                                                month.month ===
                                                    contextMonth.month
                                                    ? `var(--color-${currency})`
                                                    : 'none'
                                            }
                                            strokeDasharray={
                                                partialMonth &&
                                                month.month ===
                                                    contextMonth.month
                                                    ? '4 3'
                                                    : undefined
                                            }
                                        />
                                    ))}
                                </Bar>
                            ))}
                        </BarChart>
                    </ChartContainer>

                    <div className="grid shrink-0 gap-1.5 text-xs text-muted-foreground">
                        {partialMonth && contextMonth !== undefined && (
                            <p className="flex items-center gap-2">
                                <span className="size-2.5 rounded-sm border border-dashed border-chart-2 bg-chart-2/50" />
                                Partial month through{' '}
                                <DateText
                                    value={contextMonth.date_to}
                                    format="contextual"
                                />
                            </p>
                        )}
                        {missingActivity.map(
                            ({ currency, months }) =>
                                months.length > 0 && (
                                    <p key={currency}>
                                        No {currency} activity:{' '}
                                        {months.join(', ')}.
                                    </p>
                                ),
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
