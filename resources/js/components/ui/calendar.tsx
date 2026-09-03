import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import * as React from 'react';
import {
    DayPicker,
    getDefaultClassNames,
    type DayButton,
    type Locale,
} from 'react-day-picker';
import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

function Calendar({
    className,
    classNames,
    showOutsideDays = true,
    captionLayout = 'label',
    buttonVariant = 'ghost',
    locale,
    formatters,
    components,
    ...props
}: React.ComponentProps<typeof DayPicker> & {
    buttonVariant?: React.ComponentProps<typeof Button>['variant'];
}) {
    const defaultClassNames = getDefaultClassNames();

    return (
        <DayPicker
            showOutsideDays={showOutsideDays}
            className={cn(
                'group/calendar bg-background p-2 [--cell-radius:var(--radius-md)] [--cell-size:--spacing(8)] in-data-[slot=popover-content]:bg-transparent',
                className,
            )}
            captionLayout={captionLayout}
            locale={locale}
            formatters={{
                formatMonthDropdown: (date) =>
                    date.toLocaleString(locale?.code, { month: 'short' }),
                ...formatters,
            }}
            classNames={{
                root: cn('w-fit', defaultClassNames.root),
                months: cn(
                    'relative flex flex-col gap-4 sm:flex-row',
                    defaultClassNames.months,
                ),
                month: cn('flex w-full flex-col gap-4', defaultClassNames.month),
                nav: cn(
                    'absolute inset-x-0 top-0 flex w-full items-center justify-between gap-1',
                    defaultClassNames.nav,
                ),
                button_previous: cn(
                    buttonVariants({ variant: buttonVariant }),
                    'size-(--cell-size) p-0 select-none aria-disabled:opacity-50',
                    defaultClassNames.button_previous,
                ),
                button_next: cn(
                    buttonVariants({ variant: buttonVariant }),
                    'size-(--cell-size) p-0 select-none aria-disabled:opacity-50',
                    defaultClassNames.button_next,
                ),
                month_caption: cn(
                    'flex h-(--cell-size) w-full items-center justify-center px-(--cell-size)',
                    defaultClassNames.month_caption,
                ),
                dropdowns: cn(
                    'flex h-(--cell-size) items-center justify-center gap-1.5 text-sm font-medium',
                    defaultClassNames.dropdowns,
                ),
                dropdown_root: cn('relative rounded-md', defaultClassNames.dropdown_root),
                dropdown: cn('absolute inset-0 opacity-0', defaultClassNames.dropdown),
                caption_label: cn(
                    'flex items-center gap-1 text-sm font-medium select-none [&>svg]:size-3.5',
                    defaultClassNames.caption_label,
                ),
                month_grid: cn('w-full border-collapse', defaultClassNames.month_grid),
                weekdays: cn('flex', defaultClassNames.weekdays),
                weekday: cn(
                    'flex-1 text-xs font-normal text-muted-foreground select-none',
                    defaultClassNames.weekday,
                ),
                week: cn('mt-2 flex w-full', defaultClassNames.week),
                day: cn(
                    'group/day relative aspect-square h-full w-full p-0 text-center select-none',
                    defaultClassNames.day,
                ),
                range_start: cn('rounded-l-md bg-muted', defaultClassNames.range_start),
                range_middle: cn('rounded-none bg-muted', defaultClassNames.range_middle),
                range_end: cn('rounded-r-md bg-muted', defaultClassNames.range_end),
                today: cn('rounded-md bg-muted text-foreground', defaultClassNames.today),
                outside: cn('text-muted-foreground opacity-50', defaultClassNames.outside),
                disabled: cn('text-muted-foreground opacity-50', defaultClassNames.disabled),
                hidden: cn('invisible', defaultClassNames.hidden),
                ...classNames,
            }}
            components={{
                Chevron: ({ className: iconClassName, orientation }) => {
                    const Icon =
                        orientation === 'left'
                            ? ChevronLeft
                            : orientation === 'right'
                              ? ChevronRight
                              : ChevronDown;

                    return <Icon className={cn('size-4', iconClassName)} />;
                },
                DayButton: (dayButtonProps) => (
                    <CalendarDayButton locale={locale} {...dayButtonProps} />
                ),
                ...components,
            }}
            {...props}
        />
    );
}

function CalendarDayButton({
    className,
    day,
    modifiers,
    locale,
    ...props
}: React.ComponentProps<typeof DayButton> & { locale?: Partial<Locale> }) {
    const ref = React.useRef<HTMLButtonElement>(null);

    React.useEffect(() => {
        if (modifiers.focused) {
            ref.current?.focus();
        }
    }, [modifiers.focused]);

    return (
        <Button
            ref={ref}
            variant="ghost"
            size="icon"
            data-day={day.date.toLocaleDateString(locale?.code)}
            data-range-start={modifiers.range_start}
            data-range-end={modifiers.range_end}
            data-range-middle={modifiers.range_middle}
            className={cn(
                'relative z-10 size-(--cell-size) rounded-md border-0 font-normal data-[range-end=true]:bg-primary data-[range-end=true]:text-primary-foreground data-[range-middle=true]:rounded-none data-[range-middle=true]:bg-muted data-[range-start=true]:bg-primary data-[range-start=true]:text-primary-foreground',
                className,
            )}
            {...props}
        />
    );
}

export { Calendar, CalendarDayButton };
