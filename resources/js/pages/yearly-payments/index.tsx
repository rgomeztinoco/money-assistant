import { Form, Head } from '@inertiajs/react';
import {
    CalendarDays,
    PencilLine,
    Plus,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { Bar, BarChart, XAxis, YAxis } from 'recharts';
import { update as saveRate } from '@/actions/App/Http/Controllers/PlanningExchangeRateController';
import { update as changeState } from '@/actions/App/Http/Controllers/YearlyPaymentStateController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyHeader,
    EmptyDescription,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PaymentEditor } from '@/components/yearly-payment-editor';
import { formatContextualDate } from '@/lib/date-presentation';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { index } from '@/routes/yearly_payments';
import type { Currency } from '@/types';
import type { YearlyPayment, YearlyPaymentPlan } from '@/types/yearly-payment';

function PaymentList({
    payments,
    active,
    onEdit,
}: {
    payments: YearlyPayment[];
    active: boolean;
    onEdit: (payment: YearlyPayment) => void;
}) {
    if (payments.length === 0) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <CalendarDays />
                    </EmptyMedia>
                    <EmptyTitle>
                        {active
                            ? 'No active commitments'
                            : 'No paused commitments'}
                    </EmptyTitle>
                    {active && (
                        <EmptyDescription>
                            Mark a Spending Transaction as a yearly payment, or
                            add one here.
                        </EmptyDescription>
                    )}
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <div
            className="min-h-0 flex-1 overflow-auto"
            data-test="yearly-payments-scroll"
        >
            <table className="w-full caption-bottom text-sm">
                <TableHeader className="sticky top-0 bg-background">
                    <TableRow>
                        <TableHead>Commitment</TableHead>
                        <TableHead>Due</TableHead>
                        <TableHead className="text-right">
                            Yearly target
                        </TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {payments.map((payment) => (
                        <TableRow key={payment.id}>
                            <TableCell className="max-w-60 font-medium break-words whitespace-normal">
                                {payment.name}
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {payment.due_month_day
                                    ? formatContextualDate(
                                          `2000-${payment.due_month_day}`,
                                      )
                                    : '—'}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMinorUnits(
                                    payment.target_minor,
                                    payment.currency,
                                )}
                                {BigInt(payment.cushion_minor) > 0n && (
                                    <p className="type-meta text-muted-foreground">
                                        {`${formatMinorUnits(payment.amount_minor, payment.currency)} + ${formatMinorUnits(payment.cushion_minor, payment.currency)} cushion`}
                                    </p>
                                )}
                            </TableCell>
                            <TableCell>
                                <div className="flex items-center justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => onEdit(payment)}
                                        aria-label={`Edit ${payment.name}`}
                                    >
                                        <PencilLine />
                                    </Button>
                                    <Form
                                        {...changeState.form(payment.id)}
                                        options={{
                                            preserveScroll: true,
                                            preserveState: true,
                                        }}
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="is_active"
                                                    value={active ? '0' : '1'}
                                                />
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                    type="submit"
                                                    aria-label={`${active ? 'Pause' : 'Resume'} ${payment.name}`}
                                                >
                                                    {processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    {active
                                                        ? 'Pause'
                                                        : 'Resume'}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </table>
        </div>
    );
}

const costChartConfig = {
    relative: { label: 'Yearly target', color: 'var(--chart-1)' },
};

function AnnualCosts({ payments }: { payments: YearlyPayment[] }) {
    const [currency, setCurrency] = useState<Currency>(
        payments.some((payment) => payment.currency === 'USD') &&
            !payments.some((payment) => payment.currency === 'PEN')
            ? 'USD'
            : 'PEN',
    );
    const largest = payments
        .filter((payment) => payment.currency === currency)
        .sort((a, b) =>
            BigInt(a.target_minor) > BigInt(b.target_minor)
                ? -1
                : BigInt(a.target_minor) < BigInt(b.target_minor)
                  ? 1
                  : a.id - b.id,
        )
        .slice(0, 5);
    const maximum = BigInt(largest[0]?.target_minor ?? '1') || 1n;
    const data = largest.map((payment) => ({
        id: String(payment.id),
        relative:
            Number((BigInt(payment.target_minor) * 10000n) / maximum) / 100,
    }));

    return (
        <Card className="order-3 min-w-0 gap-3 xl:order-none">
            <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle>Largest annual costs</CardTitle>
                <Tabs
                    value={currency}
                    onValueChange={(value) => setCurrency(value as Currency)}
                >
                    <TabsList aria-label="Annual costs currency">
                        <TabsTrigger value="PEN">PEN</TabsTrigger>
                        <TabsTrigger value="USD">USD</TabsTrigger>
                    </TabsList>
                </Tabs>
            </CardHeader>
            <CardContent>
                {largest.length ? (
                    <>
                        <ChartContainer
                            config={costChartConfig}
                            className="aspect-auto w-full"
                            style={{
                                height: Math.max(64, largest.length * 40),
                            }}
                            aria-label={`Largest annual costs in ${currency}`}
                        >
                            <BarChart
                                accessibilityLayer
                                data={data}
                                layout="vertical"
                                margin={{ left: 0, right: 8 }}
                            >
                                <XAxis type="number" hide domain={[0, 100]} />
                                <YAxis
                                    type="category"
                                    dataKey="id"
                                    axisLine={false}
                                    tickLine={false}
                                    width={110}
                                    tickFormatter={(id) => {
                                        const name =
                                            largest.find(
                                                (payment) =>
                                                    String(payment.id) ===
                                                    String(id),
                                            )?.name ?? '';

                                        return name.length > 16
                                            ? `${name.slice(0, 15)}…`
                                            : name;
                                    }}
                                />
                                <ChartTooltip
                                    content={(props) => {
                                        const payment = largest.find(
                                            (payment) =>
                                                String(payment.id) ===
                                                String(props.label),
                                        );

                                        return (
                                            <ChartTooltipContent
                                                active={props.active}
                                                payload={props.payload}
                                                label={props.label}
                                                config={costChartConfig}
                                                formatLabel={() =>
                                                    payment?.name
                                                }
                                                formatValue={() =>
                                                    payment
                                                        ? formatMinorUnits(
                                                              payment.target_minor,
                                                              currency,
                                                          )
                                                        : ''
                                                }
                                            />
                                        );
                                    }}
                                />
                                <Bar
                                    dataKey="relative"
                                    fill="var(--color-relative)"
                                    radius={4}
                                    maxBarSize={24}
                                    isAnimationActive={false}
                                />
                            </BarChart>
                        </ChartContainer>
                        <ul className="sr-only">
                            {largest.map((payment) => (
                                <li key={payment.id}>
                                    {payment.name}:{' '}
                                    {formatMinorUnits(
                                        payment.target_minor,
                                        currency,
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <p className="py-6 type-meta text-muted-foreground">
                        No active commitments in {currency}.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export default function YearlyPayments({ plan }: { plan: YearlyPaymentPlan }) {
    const [open, setOpen] = useState(false);
    const [rateOpen, setRateOpen] = useState(false);
    const [editing, setEditing] = useState<YearlyPayment | null>(null);
    const combined = plan.combined_estimate;
    const active = plan.commitments.filter((payment) => payment.is_active);
    const paused = plan.commitments.filter((payment) => !payment.is_active);
    const edit = (payment: YearlyPayment) => {
        setEditing(payment);
        setOpen(true);
    };

    return (
        <>
            <Head title="Yearly payments" />
            <main className="flex min-h-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex shrink-0 flex-wrap items-center justify-between gap-3">
                    <h1 className="type-page-title">Yearly payments</h1>
                    <Button
                        onClick={() => {
                            setEditing(null);
                            setOpen(true);
                        }}
                    >
                        <Plus data-icon="inline-start" />
                        Add yearly payment
                    </Button>
                </div>
                <div className="grid min-h-0 flex-1 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] xl:grid-rows-[auto_minmax(0,1fr)]">
                    <aside className="contents min-h-0 min-w-0 flex-col gap-4 xl:col-start-2 xl:row-span-2 xl:flex">
                        <Card className="order-1 gap-3 xl:order-none">
                            <CardHeader className="flex-row items-center justify-between">
                                <CardTitle>Monthly plan</CardTitle>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Edit planning rate"
                                    onClick={() => setRateOpen(true)}
                                >
                                    <SlidersHorizontal />
                                </Button>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div className="grid grid-cols-2 gap-4">
                                    {plan.native_targets.map((target) => (
                                        <dl
                                            key={target.currency}
                                            className="flex min-w-0 flex-col gap-1"
                                        >
                                            <dt className="type-meta text-muted-foreground">
                                                {target.currency}
                                            </dt>
                                            <dd className="overflow-x-auto text-xl font-semibold whitespace-nowrap tabular-nums">
                                                {formatMinorUnits(
                                                    target.monthly_recommendation_minor,
                                                    target.currency,
                                                )}
                                            </dd>
                                            <dd className="type-meta text-muted-foreground">
                                                {formatMinorUnits(
                                                    target.annual_target_minor,
                                                    target.currency,
                                                )}{' '}
                                                / year
                                            </dd>
                                        </dl>
                                    ))}
                                </div>
                                {plan.native_targets.some(
                                    (target) =>
                                        target.currency === 'USD' &&
                                        BigInt(target.annual_target_minor) > 0n,
                                ) && (
                                    <dl className="flex items-center justify-between gap-3 border-t pt-3 text-sm">
                                        <dt className="text-muted-foreground">
                                            Combined PEN estimate
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {combined.monthly_recommendation_minor !==
                                            null ? (
                                                formatMinorUnits(
                                                    combined.monthly_recommendation_minor,
                                                    'PEN',
                                                )
                                            ) : (
                                                <Button
                                                    variant="link"
                                                    size="sm"
                                                    onClick={() =>
                                                        setRateOpen(true)
                                                    }
                                                >
                                                    Set planning rate
                                                </Button>
                                            )}
                                        </dd>
                                    </dl>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setRateOpen(true)}
                                    className="self-start type-meta text-muted-foreground underline-offset-4 hover:underline"
                                >
                                    {plan.planning_rate.pen_per_usd
                                        ? `1 USD = S/ ${plan.planning_rate.pen_per_usd}`
                                        : 'Set an optional planning rate'}
                                </button>
                            </CardContent>
                        </Card>
                        <AnnualCosts payments={active} />
                    </aside>
                    <Card className="order-2 min-h-0 min-w-0 gap-3 xl:order-none xl:col-start-1 xl:row-span-2 xl:row-start-1">
                        <CardHeader>
                            <CardTitle>Commitments</CardTitle>
                        </CardHeader>
                        <CardContent className="flex min-h-0 flex-1 flex-col">
                            <Tabs
                                defaultValue="active"
                                className="flex min-h-0 flex-1 flex-col gap-3"
                            >
                                <TabsList className="shrink-0">
                                    <TabsTrigger value="active">
                                        Active{' '}
                                        <Badge variant="secondary">
                                            {active.length}
                                        </Badge>
                                    </TabsTrigger>
                                    <TabsTrigger value="paused">
                                        Paused{' '}
                                        <Badge variant="secondary">
                                            {paused.length}
                                        </Badge>
                                    </TabsTrigger>
                                </TabsList>
                                <TabsContent
                                    value="active"
                                    className="flex min-h-0 flex-col"
                                >
                                    <PaymentList
                                        payments={active}
                                        active
                                        onEdit={edit}
                                    />
                                </TabsContent>
                                <TabsContent
                                    value="paused"
                                    className="flex min-h-0 flex-col"
                                >
                                    <PaymentList
                                        payments={paused}
                                        active={false}
                                        onEdit={edit}
                                    />
                                </TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>
                </div>
            </main>
            <Dialog open={rateOpen} onOpenChange={setRateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Planning exchange rate</DialogTitle>
                        <DialogDescription>
                            Convert USD targets into a combined PEN estimate.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...saveRate.form()}
                        options={{ preserveScroll: true, preserveState: true }}
                        onSuccess={() => setRateOpen(false)}
                    >
                        {({ errors, processing }) => (
                            <FieldGroup>
                                <Field data-invalid={!!errors.pen_per_usd}>
                                    <FieldLabel htmlFor="planning-rate">
                                        PEN per USD
                                    </FieldLabel>
                                    <Input
                                        id="planning-rate"
                                        name="pen_per_usd"
                                        type="text"
                                        inputMode="decimal"
                                        defaultValue={
                                            plan.planning_rate.pen_per_usd ?? ''
                                        }
                                        placeholder="3.75"
                                        maxLength={255}
                                        aria-invalid={!!errors.pen_per_usd}
                                    />
                                    <p className="type-meta text-muted-foreground">
                                        Leave blank to clear.
                                    </p>
                                    <InputError message={errors.pen_per_usd} />
                                </Field>
                                <DialogFooter>
                                    <Button
                                        variant="outline"
                                        type="button"
                                        onClick={() => setRateOpen(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save planning rate
                                    </Button>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {editing
                                ? 'Edit yearly payment'
                                : 'Add yearly payment'}
                        </DialogTitle>
                        <DialogDescription>
                            Annual cost and optional recurring due date.
                        </DialogDescription>
                    </DialogHeader>
                    <PaymentEditor
                        key={editing?.id ?? 'new'}
                        payment={editing}
                        onSaved={() => setOpen(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

YearlyPayments.layout = {
    breadcrumbs: [{ title: 'Yearly payments', href: index() }],
    viewportConstrained: true,
};
