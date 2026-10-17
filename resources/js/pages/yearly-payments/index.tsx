import { Form, Head } from '@inertiajs/react';
import { CalendarDays, PencilLine, Plus } from 'lucide-react';
import { useState } from 'react';
import { update as saveRate } from '@/actions/App/Http/Controllers/PlanningExchangeRateController';
import { update as changeState } from '@/actions/App/Http/Controllers/YearlyPaymentStateController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyHeader,
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
import { formatMinorUnits } from '@/lib/format-minor-units';
import { index } from '@/routes/yearly_payments';
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
                        <TableHead>Name</TableHead>
                        <TableHead className="text-right">
                            Yearly target
                        </TableHead>
                        <TableHead>Due month/day</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {payments.map((payment) => (
                        <TableRow key={payment.id}>
                            <TableCell className="max-w-60 font-medium break-words whitespace-normal">
                                {payment.name}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMinorUnits(
                                    payment.target_minor,
                                    payment.currency,
                                )}
                                <p className="type-meta text-muted-foreground">
                                    {formatMinorUnits(
                                        payment.amount_minor,
                                        payment.currency,
                                    )}{' '}
                                    +{' '}
                                    {formatMinorUnits(
                                        payment.cushion_minor,
                                        payment.currency,
                                    )}{' '}
                                    cushion
                                </p>
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                {payment.due_month_day ?? 'Not set'}
                            </TableCell>
                            <TableCell>
                                <div className="flex justify-end gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onEdit(payment)}
                                        aria-label={`Edit ${payment.name}`}
                                    >
                                        <PencilLine data-icon="inline-start" />
                                        Edit
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
                                                    variant="outline"
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

export default function YearlyPayments({ plan }: { plan: YearlyPaymentPlan }) {
    const [open, setOpen] = useState(false);
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
                <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_19rem] lg:grid-rows-[auto_minmax(0,1fr)]">
                    <Card className="gap-3 lg:col-start-2">
                        <CardHeader>
                            <CardTitle>Monthly targets</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                            {plan.native_targets.map((target) => (
                                <dl
                                    key={target.currency}
                                    className="flex flex-col gap-1"
                                >
                                    <dt className="type-meta text-muted-foreground">
                                        {target.currency}
                                    </dt>
                                    <dd className="text-2xl font-semibold tabular-nums">
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
                            <dl className="flex flex-col gap-1">
                                <dt className="type-meta text-muted-foreground">
                                    Combined PEN estimate
                                </dt>
                                <dd className="text-2xl font-semibold tabular-nums">
                                    {combined.monthly_recommendation_minor !==
                                    null
                                        ? formatMinorUnits(
                                              combined.monthly_recommendation_minor,
                                              'PEN',
                                          )
                                        : 'Set planning rate'}
                                </dd>
                                <dd className="type-meta text-muted-foreground">
                                    {combined.annual_target_minor !== null
                                        ? `${formatMinorUnits(combined.annual_target_minor, 'PEN')} / year`
                                        : 'USD conversion unavailable'}
                                </dd>
                            </dl>
                        </CardContent>
                    </Card>
                    <Card className="min-h-0 gap-3 lg:col-start-2 lg:row-start-2">
                        <CardHeader>
                            <CardTitle>Planning rate</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-y-auto">
                            <Form
                                key={
                                    plan.planning_rate.pen_per_usd ?? 'no-rate'
                                }
                                {...saveRate.form()}
                                options={{
                                    preserveScroll: true,
                                    preserveState: true,
                                }}
                                className="shrink-0"
                            >
                                {({ errors, processing }) => (
                                    <FieldGroup>
                                        <Field
                                            data-invalid={!!errors.pen_per_usd}
                                        >
                                            <div className="flex flex-wrap items-end gap-3">
                                                <div className="flex flex-col gap-2">
                                                    <FieldLabel htmlFor="planning-rate">
                                                        Planning rate · PEN per
                                                        USD
                                                    </FieldLabel>
                                                    <Input
                                                        id="planning-rate"
                                                        name="pen_per_usd"
                                                        type="text"
                                                        inputMode="decimal"
                                                        defaultValue={
                                                            plan.planning_rate
                                                                .pen_per_usd ??
                                                            ''
                                                        }
                                                        placeholder="3.75"
                                                        maxLength={255}
                                                        aria-invalid={
                                                            !!errors.pen_per_usd
                                                        }
                                                        className="w-48"
                                                    />
                                                </div>
                                                <Button
                                                    variant="outline"
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    Save planning rate
                                                </Button>
                                                <span className="type-meta text-muted-foreground">
                                                    {plan.planning_rate
                                                        .pen_per_usd
                                                        ? `Manual assumption: ${plan.planning_rate.pen_per_usd} PEN per USD`
                                                        : 'Optional · blank to clear'}
                                                </span>
                                            </div>
                                            <InputError
                                                message={errors.pen_per_usd}
                                            />
                                        </Field>
                                    </FieldGroup>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                    <Card className="min-h-0 min-w-0 gap-3 lg:col-start-1 lg:row-span-2 lg:row-start-1">
                        <CardHeader>
                            <CardTitle>Commitments</CardTitle>
                        </CardHeader>
                        <CardContent className="flex min-h-0 flex-1 flex-col">
                            <Tabs
                                defaultValue="active"
                                className="flex min-h-0 flex-1 flex-col gap-3"
                            >
                                <TabsList variant="line" className="shrink-0">
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
