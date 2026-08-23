import { Link } from '@inertiajs/react';
import { CalendarDays, Tags } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';
import { selectionUrl } from './links';
import type {
    BreakdownCategoryGroup,
    BreakdownDay,
    BreakdownPeriod,
} from './types';

function absoluteMinorUnits(value: string): bigint {
    const amount = BigInt(value);

    return amount < 0n ? -amount : amount;
}

function relativeSize(value: string, values: string[]): number {
    const maximum = values.reduce((largest, candidate) => {
        const amount = absoluteMinorUnits(candidate);

        return amount > largest ? amount : largest;
    }, 0n);

    if (maximum === 0n) {
        return 0;
    }

    const hundredths = (absoluteMinorUnits(value) * 10_000n) / maximum;

    return Math.max(1, Number(hundredths) / 100);
}

function categoryKey(categoryId: number | null): string {
    return categoryId === null ? 'uncategorized' : categoryId.toString();
}

export function CategoryChart({
    currency,
    period,
    groups,
    selectedCategory,
    selectedDay,
}: {
    currency: Currency;
    period: BreakdownPeriod;
    groups: BreakdownCategoryGroup[];
    selectedCategory: string | null;
    selectedDay: string | null;
}) {
    const amounts = groups.map((group) => group.amount_minor);

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-3">
                    <Tags className="size-5 text-muted-foreground" />
                    <Badge variant="outline">{currency} only</Badge>
                </div>
                <CardTitle>Where the money went</CardTitle>
                <CardDescription>
                    Ranked Net Spending by top-level Category. Choose one to
                    drill into its Categories, merchants, and Transactions.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {groups.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        No Category spending appears in this selection.
                    </p>
                ) : (
                    <ol className="grid gap-4">
                        {groups.map((group) => {
                            const key = categoryKey(group.category.id);
                            const isSelected = selectedCategory === key;

                            return (
                                <li key={key} className="grid gap-2">
                                    <Link
                                        href={selectionUrl({
                                            currency,
                                            period,
                                            category: isSelected ? null : key,
                                            day: selectedDay,
                                            selected: null,
                                        })}
                                        preserveScroll
                                        aria-current={
                                            isSelected ? 'true' : undefined
                                        }
                                        data-test={`breakdown-category-${key}`}
                                        className={`grid gap-2 rounded-lg border p-3 text-left transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden ${isSelected ? 'border-primary/50 bg-primary/5' : ''}`}
                                    >
                                        <span className="flex items-baseline justify-between gap-3">
                                            <span className="min-w-0 truncate font-medium">
                                                {group.category.name}
                                            </span>
                                            <span className="text-sm font-semibold whitespace-nowrap tabular-nums">
                                                {formatMinorUnits(
                                                    group.amount_minor,
                                                    currency,
                                                )}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                                                <span
                                                    className={`block h-full rounded-full ${group.amount_minor.startsWith('-') ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-primary'}`}
                                                    style={{
                                                        width: `${relativeSize(group.amount_minor, amounts)}%`,
                                                    }}
                                                />
                                            </span>
                                            <span className="w-16 text-right text-xs text-muted-foreground tabular-nums">
                                                {group.percentage}%
                                            </span>
                                        </span>
                                    </Link>

                                    {isSelected &&
                                        group.children.length > 0 && (
                                            <dl className="grid gap-2 border-l-2 border-primary/20 py-1 pl-4">
                                                {group.children.map((child) => (
                                                    <div
                                                        key={child.category.id}
                                                        className="flex items-center justify-between gap-3 text-sm"
                                                    >
                                                        <dt className="text-muted-foreground">
                                                            {
                                                                child.category
                                                                    .name
                                                            }
                                                        </dt>
                                                        <dd className="font-medium tabular-nums">
                                                            {formatMinorUnits(
                                                                child.amount_minor,
                                                                currency,
                                                            )}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

export function DailyChart({
    currency,
    period,
    days,
    selectedCategory,
    selectedDay,
}: {
    currency: Currency;
    period: BreakdownPeriod;
    days: BreakdownDay[];
    selectedCategory: string | null;
    selectedDay: string | null;
}) {
    const amounts = days.map((day) => day.net_spending_minor);

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-3">
                    <CalendarDays className="size-5 text-muted-foreground" />
                    {selectedCategory !== null && (
                        <Badge variant="secondary">Category filtered</Badge>
                    )}
                </div>
                <CardTitle>Daily spikes</CardTitle>
                <CardDescription>
                    Each bar is one recorded day. Choose a day to filter the
                    same Category chart and supporting detail.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {days.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        No daily spending appears in this selection.
                    </p>
                ) : (
                    <div className="flex min-h-52 items-end gap-2 overflow-x-auto pb-2">
                        {days.map((day) => {
                            const isSelected = selectedDay === day.date;

                            return (
                                <Link
                                    key={day.date}
                                    href={selectionUrl({
                                        currency,
                                        period,
                                        category: selectedCategory,
                                        day: isSelected ? null : day.date,
                                        selected: null,
                                    })}
                                    preserveScroll
                                    aria-current={
                                        isSelected ? 'true' : undefined
                                    }
                                    aria-label={`${day.date}, ${formatMinorUnits(day.net_spending_minor, currency)}, ${day.transaction_count} Transactions`}
                                    data-test={`breakdown-day-${day.date}`}
                                    className={`group flex min-w-14 flex-1 flex-col items-center gap-2 rounded-lg border px-2 py-3 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden ${isSelected ? 'border-primary/50 bg-primary/5' : ''}`}
                                >
                                    <span className="text-xs font-medium tabular-nums">
                                        {formatMinorUnits(
                                            day.net_spending_minor,
                                            currency,
                                        )}
                                    </span>
                                    <span className="flex h-28 w-5 items-end overflow-hidden rounded-full bg-muted">
                                        <span
                                            className={`block w-full rounded-full ${day.net_spending_minor.startsWith('-') ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-primary'}`}
                                            style={{
                                                height: `${relativeSize(day.net_spending_minor, amounts)}%`,
                                            }}
                                        />
                                    </span>
                                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                                        {day.date.slice(5)}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
