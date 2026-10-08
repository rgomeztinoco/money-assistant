import { Form, Head } from '@inertiajs/react';
import { CalendarDays, PencilLine, Plus } from 'lucide-react';
import { useState } from 'react';
import { update as saveRate } from '@/actions/App/Http/Controllers/PlanningExchangeRateController';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/YearlyPaymentController';
import { update as changeState } from '@/actions/App/Http/Controllers/YearlyPaymentStateController';
import { DateText } from '@/components/date-time';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    formatMinorUnits,
    minorUnitsToCurrencyUnits,
} from '@/lib/format-minor-units';
import { index } from '@/routes/yearly_payments';
import type { YearlyPayment, YearlyPaymentPlan } from '@/types/yearly-payment';

function PaymentEditor({
    payment,
    onSaved,
}: {
    payment: YearlyPayment | null;
    onSaved: () => void;
}) {
    return (
        <Form
            {...(payment ? update.form(payment.id) : store.form())}
            onSuccess={onSaved}
        >
            {({ errors, processing }) => (
                <FieldGroup>
                    <Field data-invalid={!!errors.name}>
                        <FieldLabel htmlFor="payment-name">Name</FieldLabel>
                        <Input
                            id="payment-name"
                            name="name"
                            defaultValue={payment?.name ?? ''}
                            maxLength={255}
                            required
                            aria-invalid={!!errors.name}
                            placeholder="Insurance, course renewal, pet checkup..."
                        />
                        <InputError message={errors.name} />
                    </Field>
                    <Field data-invalid={!!errors.amount}>
                        <FieldLabel htmlFor="payment-amount">
                            Yearly base amount
                        </FieldLabel>
                        <Input
                            id="payment-amount"
                            name="amount"
                            type="number"
                            inputMode="decimal"
                            min="0.01"
                            step="0.01"
                            required
                            defaultValue={
                                payment
                                    ? minorUnitsToCurrencyUnits(
                                          payment.amount_minor,
                                      )
                                    : ''
                            }
                            aria-invalid={!!errors.amount}
                            placeholder="2400.00"
                        />
                        <InputError message={errors.amount} />
                    </Field>
                    <Field data-invalid={!!errors.currency}>
                        <FieldLabel htmlFor="payment-currency">
                            Currency
                        </FieldLabel>
                        <NativeSelect
                            id="payment-currency"
                            name="currency"
                            defaultValue={payment?.currency ?? 'PEN'}
                            options={[
                                { value: 'PEN', label: 'PEN' },
                                { value: 'USD', label: 'USD' },
                            ]}
                            aria-invalid={!!errors.currency}
                        />
                        <InputError message={errors.currency} />
                    </Field>
                    <Field data-invalid={!!errors.cushion}>
                        <FieldLabel htmlFor="payment-cushion">
                            Cushion (optional)
                        </FieldLabel>
                        <Input
                            id="payment-cushion"
                            name="cushion"
                            type="number"
                            inputMode="decimal"
                            min="0"
                            step="0.01"
                            defaultValue={
                                payment
                                    ? minorUnitsToCurrencyUnits(
                                          payment.cushion_minor,
                                      )
                                    : ''
                            }
                            aria-invalid={!!errors.cushion}
                            placeholder="0.00"
                        />
                        <FieldDescription>
                            An extra amount in the same currency to allow for
                            uncertain costs.
                        </FieldDescription>
                        <InputError message={errors.cushion} />
                    </Field>
                    <Field data-invalid={!!errors.expected_due_on}>
                        <FieldLabel htmlFor="payment-due">
                            Expected due date (optional)
                        </FieldLabel>
                        <Input
                            id="payment-due"
                            name="expected_due_on"
                            type="date"
                            defaultValue={payment?.expected_due_on ?? ''}
                            aria-invalid={!!errors.expected_due_on}
                        />
                        <FieldDescription>
                            Used for upcoming reminders only. Dates do not
                            change your targets or renew automatically.
                        </FieldDescription>
                        <InputError message={errors.expected_due_on} />
                    </Field>
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner data-icon="inline-start" />}
                            {payment ? 'Save payment' : 'Add payment'}
                        </Button>
                    </DialogFooter>
                </FieldGroup>
            )}
        </Form>
    );
}

function PaymentList({
    payments,
    active,
    onEdit,
}: {
    payments: YearlyPayment[];
    active: boolean;
    onEdit: (payment: YearlyPayment) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {active ? 'Active commitments' : 'Paused commitments'}
                </CardTitle>
                <CardDescription>
                    {active
                        ? 'Each payment contributes its base amount plus cushion to one full year.'
                        : 'Kept for later. These payments do not contribute to your targets.'}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {payments.length === 0 ? (
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
                            <EmptyDescription>
                                {active
                                    ? 'Add a yearly payment to start your plan. You can resume a paused payment at any time.'
                                    : 'Pause a payment when you no longer plan to make it.'}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead className="text-right">
                                    Base amount
                                </TableHead>
                                <TableHead className="text-right">
                                    Cushion
                                </TableHead>
                                <TableHead className="text-right">
                                    Yearly target
                                </TableHead>
                                <TableHead>Expected due</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
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
                                            payment.amount_minor,
                                            payment.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMinorUnits(
                                            payment.cushion_minor,
                                            payment.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMinorUnits(
                                            payment.target_minor,
                                            payment.currency,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {payment.expected_due_on ? (
                                            <DateText
                                                value={payment.expected_due_on}
                                            />
                                        ) : (
                                            'Not set'
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => onEdit(payment)}
                                                aria-label={`Edit ${payment.name}`}
                                            >
                                                <PencilLine data-icon="inline-start" />
                                                Edit
                                            </Button>
                                            <Form
                                                {...changeState.form(
                                                    payment.id,
                                                )}
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="is_active"
                                                            value={
                                                                active
                                                                    ? '0'
                                                                    : '1'
                                                            }
                                                        />
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
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
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

export default function YearlyPayments({ plan }: { plan: YearlyPaymentPlan }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<YearlyPayment | null>(null);
    const combined = plan.combined_estimate;

    return (
        <>
            <Head title="Yearly payments" />
            <main className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Yearly payments
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Plan one full year of commitments and a steady
                            amount to put aside each month.
                        </p>
                    </div>
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
                <div className="grid gap-4 lg:grid-cols-3">
                    {plan.native_targets.map((target) => (
                        <Card key={target.currency}>
                            <CardHeader>
                                <CardTitle>{target.currency} targets</CardTitle>
                                <CardDescription>
                                    All active commitments in {target.currency}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <dl className="flex flex-col gap-4">
                                    <div>
                                        <dt className="text-sm text-muted-foreground">
                                            Annual target
                                        </dt>
                                        <dd className="text-2xl font-semibold break-words tabular-nums">
                                            {formatMinorUnits(
                                                target.annual_target_minor,
                                                target.currency,
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm text-muted-foreground">
                                            Monthly saving recommendation
                                        </dt>
                                        <dd className="text-xl font-semibold break-words tabular-nums">
                                            {formatMinorUnits(
                                                target.monthly_recommendation_minor,
                                                target.currency,
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    ))}
                    <Card>
                        <CardHeader>
                            <CardTitle>Combined PEN estimate</CardTitle>
                            <CardDescription>
                                {plan.planning_rate.pen_per_usd
                                    ? `Manual assumption: ${plan.planning_rate.pen_per_usd} PEN per USD`
                                    : 'No planning exchange rate set'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {combined.annual_target_minor !== null &&
                            combined.monthly_recommendation_minor !== null ? (
                                <dl className="flex flex-col gap-4">
                                    <div>
                                        <dt className="text-sm text-muted-foreground">
                                            Annual target estimate
                                        </dt>
                                        <dd className="text-2xl font-semibold break-words tabular-nums">
                                            {formatMinorUnits(
                                                combined.annual_target_minor,
                                                'PEN',
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm text-muted-foreground">
                                            Monthly saving recommendation
                                            estimate
                                        </dt>
                                        <dd className="text-xl font-semibold break-words tabular-nums">
                                            {formatMinorUnits(
                                                combined.monthly_recommendation_minor,
                                                'PEN',
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {combined.unavailable_reason}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
                <p className="text-sm text-muted-foreground">
                    {plan.assumptions.planning_only} Monthly recommendations
                    divide each aggregate annual target by 12, rounded up to the
                    next cent. Due dates do not affect targets.
                </p>
                <Card>
                    <CardHeader>
                        <CardTitle>Planning exchange rate</CardTitle>
                        <CardDescription>
                            Enter your own PEN per USD assumption for the
                            combined estimate. Native-currency targets stay
                            separate.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            key={plan.planning_rate.pen_per_usd ?? 'no-rate'}
                            {...saveRate.form()}
                        >
                            {({ errors, processing }) => (
                                <FieldGroup>
                                    <Field data-invalid={!!errors.pen_per_usd}>
                                        <FieldLabel htmlFor="planning-rate">
                                            PEN per USD (optional)
                                        </FieldLabel>
                                        <Input
                                            id="planning-rate"
                                            name="pen_per_usd"
                                            type="text"
                                            inputMode="decimal"
                                            defaultValue={
                                                plan.planning_rate
                                                    .pen_per_usd ?? ''
                                            }
                                            placeholder="3.75"
                                            maxLength={255}
                                            aria-invalid={!!errors.pen_per_usd}
                                            className="max-w-sm"
                                        />
                                        <FieldDescription>
                                            Blank removes the assumption.
                                            Conversion rounds the final annual
                                            estimate upward to PEN cents before
                                            dividing by 12.
                                        </FieldDescription>
                                        <InputError
                                            message={errors.pen_per_usd}
                                        />
                                    </Field>
                                    <Button
                                        className="self-start"
                                        type="submit"
                                        disabled={processing}
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save planning rate
                                    </Button>
                                </FieldGroup>
                            )}
                        </Form>
                    </CardContent>
                </Card>
                <PaymentList
                    payments={plan.commitments.filter(
                        (payment) => payment.is_active,
                    )}
                    active
                    onEdit={(payment) => {
                        setEditing(payment);
                        setOpen(true);
                    }}
                />
                <PaymentList
                    payments={plan.commitments.filter(
                        (payment) => !payment.is_active,
                    )}
                    active={false}
                    onEdit={(payment) => {
                        setEditing(payment);
                        setOpen(true);
                    }}
                />
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
                            Enter your expected cost for one annual cycle.
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
};
