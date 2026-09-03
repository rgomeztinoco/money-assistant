import { Link, router } from '@inertiajs/react';
import {
    addMonths,
    addQuarters,
    addWeeks,
    addYears,
    format,
    parseISO,
} from 'date-fns';
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { DateRange } from 'react-day-picker';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as breakdownIndex } from '@/routes/breakdown';
import type { BreakdownPeriod } from './types';

const periodUnits = [
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
] satisfies ReadonlyArray<{
    value: Exclude<BreakdownPeriod['unit'], 'custom'>;
    label: string;
}>;

function moveAnchor(
    anchor: string,
    unit: BreakdownPeriod['unit'],
    direction: -1 | 1,
): string {
    const date = parseISO(anchor);
    const moved =
        unit === 'week'
            ? addWeeks(date, direction)
            : unit === 'quarter'
              ? addQuarters(date, direction)
              : unit === 'year'
                ? addYears(date, direction)
                : addMonths(date, direction);

    return format(moved, 'yyyy-MM-dd');
}

function periodHref({
    unit,
    anchor,
    currencyFilter,
}: {
    unit: Exclude<BreakdownPeriod['unit'], 'custom'>;
    anchor: string;
    currencyFilter: 'PEN' | 'USD' | null;
}) {
    return breakdownIndex({
        query: {
            currency: currencyFilter ?? undefined,
            period: unit,
            anchor,
        },
    });
}

export function PeriodControls({
    currencyFilter,
    period,
    today,
}: {
    currencyFilter: 'PEN' | 'USD' | null;
    period: BreakdownPeriod;
    today: string;
}) {
    const [calendarOpen, setCalendarOpen] = useState(false);
    const [range, setRange] = useState<DateRange | undefined>({
        from: parseISO(period.date_from),
        to: parseISO(period.date_to),
    });
    const navigationUnit = period.unit === 'custom' ? 'month' : period.unit;
    const compactLabel =
        period.date_from === period.date_to
            ? format(parseISO(period.date_from), 'MMM d, yyyy')
            : `${format(parseISO(period.date_from), 'MMM d')} to ${format(parseISO(period.date_to), 'MMM d')}`;

    function applyRange() {
        if (range?.from === undefined || range.to === undefined) {
            return;
        }

        setCalendarOpen(false);
        router.visit(
            breakdownIndex({
                query: {
                    currency: currencyFilter ?? undefined,
                    period: 'custom',
                    date_from: format(range.from, 'yyyy-MM-dd'),
                    date_to: format(range.to, 'yyyy-MM-dd'),
                },
            }),
        );
    }

    return (
        <div
            className="flex min-w-0 items-center justify-end gap-1"
            data-test="period-controls"
        >
            <div className="flex min-w-0 items-center gap-0.5">
                <Button
                    asChild
                    size="icon"
                    variant="ghost"
                    className="size-8 shrink-0"
                >
                    <Link
                        href={periodHref({
                            unit: navigationUnit,
                            anchor: moveAnchor(
                                period.anchor,
                                navigationUnit,
                                -1,
                            ),
                            currencyFilter,
                        })}
                        aria-label={`Previous ${navigationUnit}`}
                    >
                        <ChevronLeft />
                    </Link>
                </Button>
                <div
                    className="w-28 min-w-0 text-center sm:w-36 lg:w-52"
                    title={period.label}
                >
                    <p className="truncate text-sm font-semibold tabular-nums">
                        <span className="lg:hidden">{compactLabel}</span>
                        <span className="hidden lg:inline">{period.label}</span>
                    </p>
                    <p className="hidden truncate text-xs text-muted-foreground lg:block">
                        {period.date_from} to {period.date_to}
                    </p>
                </div>
                <Button
                    asChild
                    size="icon"
                    variant="ghost"
                    className="size-8 shrink-0"
                >
                    <Link
                        href={periodHref({
                            unit: navigationUnit,
                            anchor: moveAnchor(
                                period.anchor,
                                navigationUnit,
                                1,
                            ),
                            currencyFilter,
                        })}
                        aria-label={`Next ${navigationUnit}`}
                    >
                        <ChevronRight />
                    </Link>
                </Button>
            </div>

            <Button
                asChild
                variant="ghost"
                size="sm"
                className="hidden shrink-0 2xl:inline-flex"
            >
                <Link
                    href={periodHref({
                        unit: 'month',
                        anchor: today,
                        currencyFilter,
                    })}
                >
                    Today
                </Link>
            </Button>

            <Select
                value={navigationUnit}
                onValueChange={(value) => {
                    if (
                        value === 'week' ||
                        value === 'month' ||
                        value === 'quarter' ||
                        value === 'year'
                    ) {
                        router.visit(
                            periodHref({
                                unit: value,
                                anchor: period.anchor,
                                currencyFilter,
                            }),
                        );
                    }
                }}
            >
                <SelectTrigger
                    size="sm"
                    aria-label="Period unit"
                    className="hidden shrink-0 md:flex"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent align="end">
                    {periodUnits.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Popover open={calendarOpen} onOpenChange={setCalendarOpen}>
                <PopoverTrigger
                    render={
                        <Button
                            type="button"
                            variant={
                                period.unit === 'custom'
                                    ? 'secondary'
                                    : 'outline'
                            }
                            size="icon"
                            className="size-8 shrink-0"
                            aria-label="Choose a custom date range"
                        />
                    }
                >
                    <CalendarRange />
                </PopoverTrigger>
                <PopoverContent align="end" className="w-auto gap-3 p-2">
                    <Calendar
                        mode="range"
                        selected={range}
                        onSelect={setRange}
                        defaultMonth={range?.from}
                        numberOfMonths={2}
                    />
                    <div className="flex items-center justify-between gap-3 border-t px-2 pt-2">
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={periodHref({
                                    unit: 'month',
                                    anchor: today,
                                    currencyFilter,
                                })}
                            >
                                Today
                            </Link>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            disabled={
                                range?.from === undefined ||
                                range.to === undefined
                            }
                            onClick={applyRange}
                        >
                            Apply range
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    );
}
