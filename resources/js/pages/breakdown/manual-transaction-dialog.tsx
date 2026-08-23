import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { store as recordTransaction } from '@/actions/App/Http/Controllers/TransactionController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    incomeSourceOptions,
    movementDirectionOptions,
    movementKindFromValue,
    movementKindOptions,
    transferPurposeOptions,
} from '@/lib/money-movement';
import type { Currency, MovementDirection, TransactionKind } from '@/types';

export function ManualTransactionDialog({
    currency,
    today,
}: {
    currency: Currency;
    today: string;
}) {
    const [open, setOpen] = useState(false);
    const [kind, setKind] = useState<TransactionKind>('spending');
    const [direction, setDirection] = useState<MovementDirection>('debit');

    function changeKind(value: string): void {
        const nextKind = movementKindFromValue(value);

        setKind(nextKind);
        setDirection(
            nextKind === 'refund' || nextKind === 'income' ? 'credit' : 'debit',
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Plus /> Add Transaction
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add Transaction</DialogTitle>
                    <DialogDescription>
                        Record one confirmed movement. You can classify or split
                        it from the Breakdown after saving.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...recordTransaction.form()}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="manual-occurred-on">
                                        Occurrence date
                                    </Label>
                                    <Input
                                        id="manual-occurred-on"
                                        name="occurred_on"
                                        type="date"
                                        defaultValue={today}
                                    />
                                    <InputError message={errors.occurred_on} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="manual-amount">
                                        Amount
                                    </Label>
                                    <Input
                                        id="manual-amount"
                                        name="amount"
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        inputMode="decimal"
                                        placeholder="12.50"
                                    />
                                    <InputError message={errors.amount} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="manual-currency">
                                        Currency
                                    </Label>
                                    <NativeSelect
                                        id="manual-currency"
                                        name="currency"
                                        defaultValue={currency}
                                        options={[
                                            { value: 'PEN', label: 'PEN' },
                                            { value: 'USD', label: 'USD' },
                                        ]}
                                    />
                                    <InputError message={errors.currency} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="manual-kind">
                                        Movement kind
                                    </Label>
                                    <NativeSelect
                                        id="manual-kind"
                                        name="kind"
                                        value={kind}
                                        onChange={(event) =>
                                            changeKind(event.target.value)
                                        }
                                        options={movementKindOptions}
                                    />
                                    <InputError message={errors.kind} />
                                </div>
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="manual-direction">
                                        Money direction
                                    </Label>
                                    <NativeSelect
                                        id="manual-direction"
                                        name="direction"
                                        value={direction}
                                        onChange={(event) => {
                                            const value = event.target.value;

                                            if (
                                                value === 'debit' ||
                                                value === 'credit'
                                            ) {
                                                setDirection(value);
                                            }
                                        }}
                                        options={movementDirectionOptions}
                                    />
                                    <InputError message={errors.direction} />
                                </div>
                            </div>

                            {kind === 'income' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="manual-income-source">
                                        Income Source
                                    </Label>
                                    <NativeSelect
                                        id="manual-income-source"
                                        name="income_source"
                                        defaultValue="salary"
                                        options={incomeSourceOptions}
                                    />
                                    <InputError
                                        message={errors.income_source}
                                    />
                                </div>
                            )}

                            {kind === 'transfer' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="manual-transfer-purpose">
                                        Transfer Purpose
                                    </Label>
                                    <NativeSelect
                                        id="manual-transfer-purpose"
                                        name="transfer_purpose"
                                        defaultValue="internal"
                                        options={transferPurposeOptions}
                                    />
                                    <InputError
                                        message={errors.transfer_purpose}
                                    />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="manual-description">
                                    Merchant or short description
                                </Label>
                                <Input
                                    id="manual-description"
                                    name="description"
                                    maxLength={255}
                                    autoComplete="off"
                                />
                                <InputError message={errors.description} />
                            </div>

                            <details className="rounded-lg border">
                                <summary className="cursor-pointer px-4 py-3 text-sm font-medium">
                                    Optional payment source
                                </summary>
                                <div className="grid gap-4 border-t p-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="manual-instrument-label">
                                            Account or card
                                        </Label>
                                        <Input
                                            id="manual-instrument-label"
                                            name="instrument_label"
                                            maxLength={100}
                                        />
                                        <InputError
                                            message={errors.instrument_label}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="manual-last-four">
                                            Last four digits
                                        </Label>
                                        <Input
                                            id="manual-last-four"
                                            name="instrument_last_four"
                                            inputMode="numeric"
                                            pattern="[0-9]{4}"
                                            maxLength={4}
                                        />
                                        <InputError
                                            message={
                                                errors.instrument_last_four
                                            }
                                        />
                                    </div>
                                </div>
                            </details>

                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Record Transaction
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
