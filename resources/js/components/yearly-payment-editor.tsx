import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { store as linkPayment } from '@/actions/App/Http/Controllers/TransactionYearlyPaymentController';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/YearlyPaymentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldSet,
    FieldLegend,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { minorUnitsToCurrencyUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';
import type { YearlyPayment } from '@/types/yearly-payment';

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const daysPerMonth = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

export function PaymentEditor({
    payment,
    onSaved,
    transaction,
}: {
    payment: YearlyPayment | null;
    onSaved: () => void;
    transaction?: {
        id: number;
        description: string;
        amount_minor: string;
        currency: Currency;
        occurred_on: string;
    };
}) {
    const initialDueDate = payment
        ? (payment.due_month_day ?? '')
        : (transaction?.occurred_on.slice(5) ?? '');
    const [dueMonth, setDueMonth] = useState(initialDueDate.slice(0, 2));
    const [dueDay, setDueDay] = useState(initialDueDate.slice(3, 5));
    const dayCount = daysPerMonth[Number(dueMonth) - 1] ?? 0;

    return (
        <Form
            {...(payment
                ? update.form(payment.id)
                : transaction
                  ? linkPayment.form(transaction.id)
                  : store.form())}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onSaved}
        >
            {({ errors, processing }) => (
                <FieldGroup>
                    <Field data-invalid={!!errors.name}>
                        <FieldLabel htmlFor="payment-name">Name</FieldLabel>
                        <Input
                            id="payment-name"
                            name="name"
                            defaultValue={
                                payment?.name ?? transaction?.description ?? ''
                            }
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
                                payment || transaction
                                    ? minorUnitsToCurrencyUnits(
                                          (payment ?? transaction)!
                                              .amount_minor,
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
                            defaultValue={
                                payment?.currency ??
                                transaction?.currency ??
                                'PEN'
                            }
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

                        <InputError message={errors.cushion} />
                    </Field>
                    <FieldSet>
                        <FieldLegend variant="label">
                            Yearly due date (optional)
                        </FieldLegend>
                        <input
                            id="payment-due"
                            type="hidden"
                            name="due_month_day"
                            value={
                                dueMonth && dueDay
                                    ? `${dueMonth}-${dueDay}`
                                    : ''
                            }
                        />
                        <FieldGroup className="grid grid-cols-[minmax(0,1fr)_6rem] gap-4">
                            <Field data-invalid={!!errors.due_month_day}>
                                <FieldLabel htmlFor="payment-due-month">
                                    Month
                                </FieldLabel>
                                <NativeSelect
                                    id="payment-due-month"
                                    value={dueMonth}
                                    onChange={(event) => {
                                        const nextMonth = event.target.value;
                                        setDueMonth(nextMonth);

                                        if (
                                            !nextMonth ||
                                            Number(dueDay) >
                                                daysPerMonth[
                                                    Number(nextMonth) - 1
                                                ]
                                        ) {
                                            setDueDay('');
                                        }
                                    }}
                                    options={[
                                        { value: '', label: 'No due date' },
                                        ...months.map((label, index) => ({
                                            value: String(index + 1).padStart(
                                                2,
                                                '0',
                                            ),
                                            label,
                                        })),
                                    ]}
                                    aria-invalid={!!errors.due_month_day}
                                    aria-describedby={
                                        errors.due_month_day
                                            ? 'payment-due-error'
                                            : undefined
                                    }
                                />
                            </Field>
                            <Field
                                data-invalid={!!errors.due_month_day}
                                data-disabled={!dueMonth}
                            >
                                <FieldLabel htmlFor="payment-due-day">
                                    Day
                                </FieldLabel>
                                <NativeSelect
                                    id="payment-due-day"
                                    value={dueDay}
                                    onChange={(event) =>
                                        setDueDay(event.target.value)
                                    }
                                    disabled={!dueMonth}
                                    required={!!dueMonth}
                                    options={[
                                        {
                                            value: '',
                                            label: 'Day',
                                            disabled: true,
                                        },
                                        ...Array.from(
                                            { length: dayCount },
                                            (_, index) => ({
                                                value: String(
                                                    index + 1,
                                                ).padStart(2, '0'),
                                                label: String(index + 1),
                                            }),
                                        ),
                                    ]}
                                    aria-invalid={!!errors.due_month_day}
                                    aria-describedby={
                                        errors.due_month_day
                                            ? 'payment-due-error'
                                            : undefined
                                    }
                                />
                            </Field>
                        </FieldGroup>
                        <InputError
                            id="payment-due-error"
                            message={errors.due_month_day}
                        />
                    </FieldSet>
                    <InputError message={errors.yearly_payment_id} />
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
