import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CategoryPicker } from '@/components/category-picker';
import type { CategoryPickerOption } from '@/components/category-picker';
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
import { formatFullDate } from '@/lib/date-presentation';
import {
    currencyUnitsToMinorUnits,
    minorUnitsToCurrencyUnits,
} from '@/lib/format-minor-units';
import { store, update } from '@/routes/debts/entries';
import { DebtAllocationFields } from './debt-allocation-fields';
import { DebtTransactionPicker } from './debt-transaction-picker';
import type { Debt, DebtEntry } from './types';

export function DebtEntryForm({
    debt,
    categoryOptions,
    today,
    entry,
    onSaved,
}: {
    debt: Debt;
    categoryOptions: CategoryPickerOption[];
    today: string;
    entry?: DebtEntry;
    onSaved: () => void;
}) {
    const [source, setSource] = useState('existing');
    const form = useForm({
        kind: entry?.kind ?? ('repayment' as DebtEntry['kind']),
        principal: entry ? minorUnitsToCurrencyUnits(entry.principal_minor ?? entry.amount_minor) : '',
        interest: minorUnitsToCurrencyUnits(entry?.interest_minor ?? '0'),
        interest_is_new: false,
        category_id: entry?.category_id?.toString() ?? '',
        transaction_id: '',
        occurred_on: entry?.occurred_on ?? today,
        amount: entry ? minorUnitsToCurrencyUnits(entry.amount_minor) : '',
        description: entry?.description ?? '',
        reason: entry?.reason ?? '',
    });
    const charge = form.data.kind === 'interest_charge';
    const repayment = form.data.kind === 'repayment';
    const nonCash = form.data.kind === 'adjustment' || charge;
    const adjustment = form.data.kind === 'adjustment';
    const direction =
        (debt.direction === 'owed') === (form.data.kind === 'funding')
            ? 'credit'
            : 'debit';

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                const signedAmount = currencyUnitsToMinorUnits(
                    form.data.amount,
                );

                if (
                    adjustment &&
                    (signedAmount === null || signedAmount === 0n)
                ) {
                    form.setError(
                        'amount',
                        'Enter a non-zero amount with up to two decimals.',
                    );

                    return;
                }

                form.transform((data) =>
                    nonCash
                        ? {
                              kind: data.kind,
                              occurred_on: data.occurred_on,
                              ...(charge
                                  ? { amount: data.amount }
                                  : { amount_minor: signedAmount?.toString() }),
                              reason: data.reason,
                          }
                        : !entry && source === 'existing'
                          ? {
                                kind: data.kind,
                                transaction_id: data.transaction_id,
                                ...(repayment
                                    ? {
                                          principal: data.principal,
                                          interest: data.interest,
                                          interest_is_new: data.interest_is_new,
                                          category_id: data.category_id,
                                      }
                                    : {}),
                            }
                          : {
                                kind: data.kind,
                                occurred_on: data.occurred_on,
                                amount: data.amount,
                                description: data.description,
                                ...(repayment
                                    ? {
                                          principal: data.principal,
                                          interest: data.interest,
                                          interest_is_new: data.interest_is_new,
                                          category_id: data.category_id,
                                      }
                                    : {}),
                            },
                );
                const options = {
                    preserveScroll: true,
                    onSuccess: onSaved,
                };

                if (entry) {
                    form.put(
                        update.url({ debt: debt.id, entry: entry.id }),
                        options,
                    );
                } else {
                    form.post(store.url(debt.id), options);
                }
            }}
        >
            <FieldGroup>
                <Field data-invalid={Boolean(form.errors.kind)}>
                    <FieldLabel htmlFor="entry-kind">Operation</FieldLabel>
                    <NativeSelect
                        id="entry-kind"
                        aria-invalid={Boolean(form.errors.kind)}
                        value={form.data.kind}
                        disabled={Boolean(entry)}
                        onChange={(event) => {
                            form.setData(
                                'kind',
                                event.target.value as DebtEntry['kind'],
                            );
                            form.setData('transaction_id', '');
                        }}
                        options={[
                            {
                                value: 'repayment',
                                label:
                                    debt.direction === 'owed'
                                        ? 'Repayment made'
                                        : 'Payment collected',
                            },
                            {
                                value: 'funding',
                                label:
                                    debt.direction === 'owed'
                                        ? 'Additional borrowing'
                                        : 'Additional lending',
                            },
                            {
                                value: 'interest_charge',
                                label: 'Manual interest charge',
                            },
                            {
                                value: 'adjustment',
                                label: 'Non-cash adjustment',
                            },
                        ]}
                    />
                    <FieldError>{form.errors.kind}</FieldError>
                </Field>
                {!nonCash && !entry && (
                    <Field>
                        <FieldLabel htmlFor="entry-source">
                            Payment record
                        </FieldLabel>
                        <NativeSelect
                            id="entry-source"
                            value={source}
                            onChange={(event) => setSource(event.target.value)}
                            options={[
                                {
                                    value: 'existing',
                                    label: 'Use an existing Transaction',
                                },
                                {
                                    value: 'manual',
                                    label: 'Create a manual Transaction',
                                },
                            ]}
                        />
                    </Field>
                )}
                {!nonCash && !entry && source === 'existing' ? (
                    <Field data-invalid={Boolean(form.errors.transaction_id)}>
                        <FieldLabel htmlFor="entry-transaction">
                            Transaction
                        </FieldLabel>
                        <FieldDescription id="entry-transaction-filters">
                            Unallocated {debt.currency} ·{' '}
                            {direction === 'debit' ? 'Money out' : 'Money in'} ·
                            From {formatFullDate(debt.opened_on)}
                        </FieldDescription>
                        <DebtTransactionPicker
                            key={`${debt.id}:${direction}`}
                            debtId={debt.id}
                            direction={direction}
                            invalid={Boolean(form.errors.transaction_id)}
                            value={form.data.transaction_id}
                            onSelect={(transaction) => {
                                form.setData('transaction_id', String(transaction.id));
                                form.setData('principal', minorUnitsToCurrencyUnits(transaction.amount_minor));
                                form.setData('interest', '0.00');
                            }}
                        />
                        <FieldError>{form.errors.transaction_id}</FieldError>
                    </Field>
                ) : (
                    <>
                        <Field data-invalid={Boolean(form.errors.amount)}>
                            <FieldLabel htmlFor="entry-amount">
                                {adjustment ? 'Balance change' : 'Amount'} in{' '}
                                {debt.currency}
                            </FieldLabel>
                            <Input
                                id="entry-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(event) => {
                                    form.setData('amount', event.target.value);

                                    if (
                                        repayment &&
                                        form.data.interest === '0.00'
                                    ) {
                                        form.setData(
                                            'principal',
                                            event.target.value,
                                        );
                                    }
                                }}
                                aria-invalid={Boolean(form.errors.amount)}
                                required
                            />
                            <FieldDescription>
                                {adjustment
                                    ? 'Use a negative amount for forgiveness or a write-off, or a positive amount to increase the balance.'
                                    : charge
                                      ? 'This dated charge increases the balance without creating a Transaction.'
                                      : 'Confirm the full posted movement in currency units.'}
                            </FieldDescription>
                            <FieldError>{form.errors.amount}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(form.errors.occurred_on)}>
                            <FieldLabel htmlFor="entry-date">Date</FieldLabel>
                            <Input
                                id="entry-date"
                                type="date"
                                value={form.data.occurred_on}
                                min={debt.opened_on}
                                onChange={(event) =>
                                    form.setData(
                                        'occurred_on',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={Boolean(form.errors.occurred_on)}
                                required
                            />
                            <FieldError>{form.errors.occurred_on}</FieldError>
                        </Field>
                        <Field
                            data-invalid={Boolean(
                                nonCash
                                    ? form.errors.reason
                                    : form.errors.description,
                            )}
                        >
                            <FieldLabel htmlFor="entry-detail">
                                {nonCash ? 'Reason' : 'Description'}
                            </FieldLabel>
                            <Input
                                id="entry-detail"
                                aria-invalid={Boolean(
                                    nonCash
                                        ? form.errors.reason
                                        : form.errors.description,
                                )}
                                value={
                                    nonCash
                                        ? form.data.reason
                                        : form.data.description
                                }
                                onChange={(event) =>
                                    form.setData(
                                        nonCash ? 'reason' : 'description',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <FieldError>
                                {nonCash
                                    ? form.errors.reason
                                    : form.errors.description}
                            </FieldError>
                        </Field>
                    </>
                )}
                {repayment && (
                    <DebtAllocationFields
                        principal={form.data.principal}
                        interest={form.data.interest}
                        onPrincipal={(value) =>
                            form.setData('principal', value)
                        }
                        onInterest={(value) => form.setData('interest', value)}
                        newCharge={form.data.interest_is_new}
                        onNewCharge={(value) =>
                            form.setData('interest_is_new', value)
                        }
                        errors={form.errors}
                        allowNewCharge={!entry}
                    />
                )}
                {repayment &&
                    debt.direction === 'owed' &&
                    (currencyUnitsToMinorUnits(form.data.interest) ?? 0n) >
                        0n && (
                        <Field data-invalid={Boolean(form.errors.category_id)}>
                            <FieldLabel htmlFor="entry-category-trigger">
                                Interest Category
                            </FieldLabel>
                            <CategoryPicker
                                id="entry-category"
                                name="category_id"
                                options={categoryOptions}
                                value={form.data.category_id}
                                onValueChange={(value) =>
                                    form.setData('category_id', value)
                                }
                            />
                            <FieldDescription>
                                This Category applies only to the interest
                                portion.
                            </FieldDescription>
                            <FieldError>{form.errors.category_id}</FieldError>
                        </Field>
                    )}
                {Object.entries(form.errors)
                    .filter(
                        ([key]) =>
                            ![
                                'amount',
                                'description',
                                'occurred_on',
                                'reason',
                                'transaction_id',
                                'kind',
                            ].includes(key),
                    )
                    .map(([key, message]) => (
                        <FieldError key={key}>{message}</FieldError>
                    ))}
                <Button type="submit" disabled={form.processing}>
                    {entry ? 'Save changes' : 'Save operation'}
                </Button>
            </FieldGroup>
        </form>
    );
}
