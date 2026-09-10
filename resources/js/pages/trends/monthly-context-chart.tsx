import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ReferenceLine,
    XAxis,
} from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { formatMinorUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';
import type { MonthlyContext, Period } from './types';

const chartConfig = {
    total: { label: 'Net Spending', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function shortDate(date: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}

export function MonthlyContextChart({
    currency,
    period,
    months,
}: {
    currency: Currency;
    period: Period;
    months: MonthlyContext[];
}) {
    const currentMonth = period.date_from.slice(0, 7);
    const chartData = months.map((month) => ({
        ...month,
        shortLabel: month.label.split(' ')[0],
        total:
            month.total_minor === null ? null : Number(month.total_minor) / 100,
    }));
    const monthsWithoutActivity = months
        .filter((month) => month.total_minor === null)
        .map((month) => month.label);

    return (
        <section className="grid min-w-0 gap-3">
            <div>
                <h2 className="text-sm font-medium">Calendar-month context</h2>
                <p className="text-xs text-muted-foreground">
                    Six separate months in {currency}.
                </p>
            </div>

            {months.length === 0 ? (
                <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                    No six-month activity recorded.
                </p>
            ) : (
                <>
                    <ChartContainer
                        config={chartConfig}
                        className="h-40 w-full min-w-0"
                        role="img"
                        aria-label={`Six-month Net Spending context in ${currency}`}
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
                                        formatValue={(value) =>
                                            formatMinorUnits(
                                                String(
                                                    Math.round(
                                                        Number(value) * 100,
                                                    ),
                                                ),
                                                currency,
                                            )
                                        }
                                    />
                                }
                            />
                            <Bar
                                dataKey="total"
                                name="total"
                                radius={[3, 3, 3, 3]}
                                maxBarSize={36}
                                minPointSize={2}
                                isAnimationActive={false}
                            >
                                {chartData.map((month) => {
                                    const partial =
                                        month.month === currentMonth;

                                    return (
                                        <Cell
                                            key={month.month}
                                            fill="var(--color-total)"
                                            fillOpacity={partial ? 0.55 : 1}
                                            stroke={
                                                partial
                                                    ? 'var(--color-total)'
                                                    : 'none'
                                            }
                                            strokeDasharray={
                                                partial ? '4 3' : undefined
                                            }
                                        />
                                    );
                                })}
                            </Bar>
                        </BarChart>
                    </ChartContainer>

                    <div className="grid gap-1.5 text-xs text-muted-foreground">
                        <p className="flex items-center gap-2">
                            <span className="size-2.5 rounded-sm border border-dashed border-chart-2 bg-chart-2/50" />
                            Current partial month through{' '}
                            {shortDate(period.date_to)}
                        </p>
                        {monthsWithoutActivity.length > 0 && (
                            <p>
                                No recorded activity:{' '}
                                {monthsWithoutActivity.join(', ')}.
                            </p>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}
