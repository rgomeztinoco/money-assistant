import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    currencyUnitsToMinorUnits,
    minorUnitsToCurrencyUnits,
} from '@/lib/format-minor-units';
import { store, update } from '@/routes/debts';
import type { Debt } from './types';

export function DebtForm({
    debt,
    today,
    onSaved,
}: {
    debt?: Debt;
    today: string;
    onSaved?: () => void;
}) {
    const form = useForm({
        name: debt?.name ?? '',
        counterparty: debt?.counterparty ?? '',
        direction: debt?.direction ?? 'owed',
        currency: debt?.currency ?? 'PEN',
        opening_balance_minor: debt?.opening_balance_minor ?? '0',
        opened_on: debt?.opened_on ?? today,
    });
    const [opening, setOpening] = useState(
        debt ? minorUnitsToCurrencyUnits(debt.opening_balance_minor) : '',
    );
    const field = (
        name: 'name' | 'counterparty' | 'opened_on',
        label: string,
        type = 'text',
    ) => (
        <Field data-invalid={Boolean(form.errors[name])}>
            <FieldLabel htmlFor={`debt-${name}`}>{label}</FieldLabel>
            <Input
                id={`debt-${name}`}
                type={type}
                value={form.data[name]}
                aria-invalid={Boolean(form.errors[name])}
                onChange={(event) => form.setData(name, event.target.value)}
                required
            />
            <FieldError>{form.errors[name]}</FieldError>
        </Field>
    );

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                const balance = currencyUnitsToMinorUnits(opening);

                if (balance === null || balance < 0n) {
                    form.setError(
                        'opening_balance_minor',
                        'Enter a balance of zero or more with up to two decimals.',
                    );

                    return;
                }

                form.transform((data) => ({
                    ...data,
                    opening_balance_minor: balance.toString(),
                }));

                if (debt) {
                    form.put(update.url(debt.id), {
                        preserveScroll: true,
                        onSuccess: onSaved,
                    });
                } else {
                    form.post(store.url(), { onSuccess: onSaved });
                }
            }}
        >
            <FieldGroup>
                {field('name', 'Debt name')}
                {field('counterparty', 'Person or institution')}
                <Field data-invalid={Boolean(form.errors.direction)}>
                    <FieldLabel htmlFor="debt-direction">
                        Who owes the money?
                    </FieldLabel>
                    <NativeSelect
                        id="debt-direction"
                        value={form.data.direction}
                        onChange={(event) =>
                            form.setData(
                                'direction',
                                event.target.value as Debt['direction'],
                            )
                        }
                        options={[
                            { value: 'owed', label: 'I owe' },
                            { value: 'receivable', label: 'Owed to me' },
                        ]}
                    />
                    <FieldError>{form.errors.direction}</FieldError>
                </Field>
                <Field data-invalid={Boolean(form.errors.currency)}>
                    <FieldLabel htmlFor="debt-currency">Currency</FieldLabel>
                    <NativeSelect
                        id="debt-currency"
                        value={form.data.currency}
                        onChange={(event) =>
                            form.setData(
                                'currency',
                                event.target.value as Debt['currency'],
                            )
                        }
                        options={[
                            { value: 'PEN', label: 'PEN' },
                            { value: 'USD', label: 'USD' },
                        ]}
                    />
                    <FieldError>{form.errors.currency}</FieldError>
                </Field>
                <Field
                    data-invalid={Boolean(form.errors.opening_balance_minor)}
                >
                    <FieldLabel htmlFor="debt-opening">
                        Opening balance
                    </FieldLabel>
                    <Input
                        id="debt-opening"
                        inputMode="decimal"
                        value={opening}
                        onChange={(event) => setOpening(event.target.value)}
                        aria-invalid={Boolean(
                            form.errors.opening_balance_minor,
                        )}
                        required
                    />
                    <FieldDescription>
                        Enter the amount still owed on the opening date.
                        Payments already included in this balance will not be
                        applied again.
                    </FieldDescription>
                    <FieldError>{form.errors.opening_balance_minor}</FieldError>
                </Field>
                {field('opened_on', 'Opening date', 'date')}
                <Button type="submit" disabled={form.processing}>
                    {debt ? 'Save debt' : 'Create debt'}
                </Button>
            </FieldGroup>
        </form>
    );
}
