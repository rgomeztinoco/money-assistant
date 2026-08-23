import { Form, Link } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency } from '@/types';
import type { BreakdownPeriod } from './types';

const quickPeriods = [
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'rolling_30', label: 'Rolling 30 days' },
] satisfies ReadonlyArray<{
    value: 'this_month' | 'last_month' | 'rolling_30';
    label: string;
}>;

export function PeriodControls({
    currency,
    period,
    coverage,
}: {
    currency: Currency;
    period: BreakdownPeriod;
    coverage: {
        date_from: string | null;
        date_to: string | null;
        transaction_count: number;
    };
}) {
    const otherCurrency: Currency = currency === 'PEN' ? 'USD' : 'PEN';

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CalendarRange className="size-5 text-muted-foreground" />
                    <div className="flex gap-2" aria-label="Breakdown currency">
                        <Button asChild size="sm" variant="secondary">
                            <Link
                                href={breakdownIndex({
                                    query: { currency },
                                })}
                            >
                                {currency}
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={breakdownIndex({
                                    query: { currency: otherCurrency },
                                })}
                                data-test={`breakdown-switch-${otherCurrency.toLowerCase()}`}
                            >
                                {otherCurrency}
                            </Link>
                        </Button>
                    </div>
                </div>
                <CardTitle>{period.label}</CardTitle>
                <CardDescription>
                    Selected dates {period.date_from} through {period.date_to}.
                    {coverage.date_from === null
                        ? ' No Transactions fall in this period.'
                        : ` Recorded activity covers ${coverage.date_from} through ${coverage.date_to}.`}
                </CardDescription>
                <div className="flex flex-wrap gap-2 pt-1">
                    <Badge variant="outline">{currency} only</Badge>
                    <Badge variant="secondary">
                        {coverage.transaction_count}{' '}
                        {coverage.transaction_count === 1
                            ? 'Transaction'
                            : 'Transactions'}
                    </Badge>
                    {period.preset === 'latest_month' && (
                        <Badge variant="secondary">
                            Latest month with activity
                        </Badge>
                    )}
                </div>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="flex flex-wrap gap-2" aria-label="Quick period">
                    {quickPeriods.map((quickPeriod) => (
                        <Button
                            key={quickPeriod.value}
                            asChild
                            size="sm"
                            variant={
                                period.preset === quickPeriod.value
                                    ? 'secondary'
                                    : 'outline'
                            }
                        >
                            <Link
                                href={breakdownIndex({
                                    query: {
                                        currency,
                                        preset: quickPeriod.value,
                                    },
                                })}
                                data-test={`breakdown-period-${quickPeriod.value}`}
                            >
                                {quickPeriod.label}
                            </Link>
                        </Button>
                    ))}
                </div>

                <details
                    className="rounded-lg border"
                    open={period.preset === 'custom' || undefined}
                >
                    <summary className="cursor-pointer px-4 py-3 text-sm font-medium">
                        Custom range
                    </summary>
                    <Form
                        action={breakdownIndex.url()}
                        method="get"
                        className="grid gap-4 border-t p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="currency"
                                    value={currency}
                                />
                                <input
                                    type="hidden"
                                    name="preset"
                                    value="custom"
                                />
                                <div className="grid gap-2">
                                    <Label htmlFor="breakdown-date-from">
                                        From
                                    </Label>
                                    <Input
                                        id="breakdown-date-from"
                                        name="date_from"
                                        type="date"
                                        defaultValue={period.date_from}
                                        aria-invalid={
                                            errors.date_from ? true : undefined
                                        }
                                    />
                                    <InputError message={errors.date_from} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="breakdown-date-to">
                                        To
                                    </Label>
                                    <Input
                                        id="breakdown-date-to"
                                        name="date_to"
                                        type="date"
                                        defaultValue={period.date_to}
                                        aria-invalid={
                                            errors.date_to ? true : undefined
                                        }
                                    />
                                    <InputError message={errors.date_to} />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Show range
                                </Button>
                            </>
                        )}
                    </Form>
                </details>
            </CardContent>
        </Card>
    );
}
