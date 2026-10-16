import { Form } from '@inertiajs/react';
import { store as linkPayment } from '@/actions/App/Http/Controllers/TransactionYearlyPaymentController';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/YearlyPaymentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { minorUnitsToCurrencyUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';
import type { YearlyPayment } from '@/types/yearly-payment';

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
                    <Field data-invalid={!!errors.due_month_day}>
                        <FieldLabel htmlFor="payment-due">
                            Due month/day (optional)
                        </FieldLabel>
                        <Input
                            id="payment-due"
                            name="due_month_day"
                            type="text"
                            placeholder="MM-DD"
                            pattern="[0-9]{2}-[0-9]{2}"
                            maxLength={5}
                            defaultValue={
                                payment?.due_month_day ??
                                transaction?.occurred_on.slice(5) ??
                                ''
                            }
                            aria-invalid={!!errors.due_month_day}
                        />

                        <InputError message={errors.due_month_day} />
                    </Field>
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
