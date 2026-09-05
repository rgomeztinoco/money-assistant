import * as React from 'react';
import * as RechartsPrimitive from 'recharts';
import type {
    DefaultLegendContentProps,
    TooltipContentProps,
    TooltipValueType,
} from 'recharts';
import { cn } from '@/lib/utils';

export type ChartConfig = Record<
    string,
    { label?: React.ReactNode; color?: string }
>;

function ChartContainer({
    id,
    className,
    children,
    config,
    ...props
}: React.ComponentProps<'div'> & {
    config: ChartConfig;
    children: React.ComponentProps<
        typeof RechartsPrimitive.ResponsiveContainer
    >['children'];
}) {
    const uniqueId = React.useId();
    const chartId = `chart-${id ?? uniqueId.replaceAll(':', '')}`;

    return (
        <div
            data-slot="chart"
            data-chart={chartId}
            className={cn(
                'flex aspect-video justify-center text-xs [&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground [&_.recharts-cartesian-grid_line]:stroke-border/60 [&_.recharts-layer]:outline-hidden [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted',
                className,
            )}
            {...props}
        >
            <style>{`[data-chart=${chartId}] { ${Object.entries(config)
                .map(([key, value]) =>
                    value.color ? `--color-${key}: ${value.color};` : '',
                )
                .join(' ')} }`}</style>
            <RechartsPrimitive.ResponsiveContainer
                initialDimension={{ width: 320, height: 200 }}
            >
                {children}
            </RechartsPrimitive.ResponsiveContainer>
        </div>
    );
}

const ChartTooltip = RechartsPrimitive.Tooltip;
const ChartLegend = RechartsPrimitive.Legend;

function ChartTooltipContent({
    active,
    payload,
    label,
    config,
    formatLabel,
    formatValue,
    dataTest,
}: Partial<TooltipContentProps<number, string>> & {
    config: ChartConfig;
    formatLabel?: (label: string | number) => React.ReactNode;
    formatValue?: (
        value: TooltipValueType | undefined,
        name: string,
    ) => React.ReactNode;
    dataTest?: string;
}) {
    if (!active || payload === undefined || payload.length === 0) {
        return null;
    }

    return (
        <div
            className="grid min-w-32 gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-2 text-xs text-foreground shadow-xl"
            data-test={dataTest}
        >
            {label !== undefined && (
                <p className="font-medium">{formatLabel?.(label) ?? label}</p>
            )}
            <div className="grid gap-1.5">
                {payload.map((item) => {
                    const name =
                        typeof item.name === 'string'
                            ? item.name
                            : String(item.name ?? item.dataKey ?? 'Value');
                    const itemConfig = config[name];

                    return (
                        <div
                            key={`${name}-${String(item.value)}`}
                            className="flex items-center gap-2"
                        >
                            <span
                                className="size-2.5 shrink-0 rounded-[2px] border"
                                style={{
                                    backgroundColor:
                                        item.color ??
                                        item.fill ??
                                        itemConfig?.color,
                                    borderColor:
                                        item.color ??
                                        item.fill ??
                                        itemConfig?.color,
                                }}
                            />
                            <span className="flex min-w-0 flex-1 items-center justify-between gap-4">
                                <span className="text-muted-foreground">
                                    {itemConfig?.label ?? name}
                                </span>
                                <span className="font-mono font-medium tabular-nums">
                                    {formatValue?.(item.value, name) ??
                                        String(item.value ?? '')}
                                </span>
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function ChartLegendContent({
    payload,
    config,
    className,
}: Partial<DefaultLegendContentProps> & {
    config: ChartConfig;
    className?: string;
}) {
    if (payload === undefined || payload.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex items-center justify-center gap-4 pt-2',
                className,
            )}
            data-test="chart-legend"
        >
            {payload.map((item) => {
                const key = String(item.dataKey ?? item.value ?? 'value');
                const itemConfig = config[key];

                return (
                    <div key={key} className="flex items-center gap-1.5">
                        <span
                            className="size-2.5 shrink-0 rounded-[2px]"
                            style={{
                                backgroundColor:
                                    item.color ?? itemConfig?.color,
                            }}
                        />
                        <span className="text-muted-foreground">
                            {itemConfig?.label ?? item.value ?? key}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

export {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
};
