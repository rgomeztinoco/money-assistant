import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    show,
    store,
} from '@/actions/App/Http/Controllers/TransactionYearlyPaymentController';
import InputError from '@/components/input-error';
import type { EditorTransaction } from '@/components/transaction-editor';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { PaymentEditor } from '@/components/yearly-payment-editor';
import type { YearlyPayment } from '@/types/yearly-payment';

type PaymentOptions = {
    commitments: YearlyPayment[];
    yearly_payment_id: number | null;
};

export function TransactionYearlyPaymentDialog({
    transaction,
    onClose,
}: {
    transaction: EditorTransaction;
    onClose: () => void;
}) {
    const [options, setOptions] = useState<PaymentOptions | null>(null);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const [selected, setSelected] = useState('new');

    useEffect(() => {
        const controller = new AbortController();
        fetch(show.url(transaction.id), {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Unavailable');
                }

                return response.json() as Promise<PaymentOptions>;
            })
            .then((result) => {
                setOptions(result);
                setFailed(false);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [transaction.id, attempt]);

    const linked =
        options?.commitments.find(
            (payment) => payment.id === options.yearly_payment_id,
        ) ?? null;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {linked
                            ? 'Edit yearly payment'
                            : 'Mark as yearly payment'}
                    </DialogTitle>
                    <DialogDescription>
                        {linked ? linked.name : transaction.description}
                    </DialogDescription>
                </DialogHeader>
                {failed ? (
                    <div className="flex flex-col gap-3">
                        <p role="alert">Could not load yearly payments.</p>
                        <Button
                            variant="outline"
                            onClick={() => setAttempt(attempt + 1)}
                        >
                            Try again
                        </Button>
                    </div>
                ) : !options ? (
                    <div role="status" className="flex items-center gap-2">
                        <Spinner />
                        Loading yearly payments
                    </div>
                ) : linked ? (
                    <PaymentEditor payment={linked} onSaved={onClose} />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="yearly-payment-choice">
                                Yearly payment
                            </FieldLabel>
                            <NativeSelect
                                id="yearly-payment-choice"
                                value={selected}
                                onChange={(event) =>
                                    setSelected(event.target.value)
                                }
                                options={[
                                    {
                                        value: 'new',
                                        label: 'Create yearly payment',
                                    },
                                    ...options.commitments.map((payment) => ({
                                        value: String(payment.id),
                                        label: `${payment.name}${payment.is_active ? '' : ' (paused)'}`,
                                    })),
                                ]}
                            />
                        </Field>
                        {selected === 'new' ? (
                            <PaymentEditor
                                payment={null}
                                transaction={transaction}
                                onSaved={onClose}
                            />
                        ) : (
                            <Form
                                {...store.form(transaction.id)}
                                options={{
                                    preserveScroll: true,
                                    preserveState: true,
                                }}
                                onSuccess={onClose}
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="yearly_payment_id"
                                            value={selected}
                                        />
                                        <InputError
                                            message={errors.yearly_payment_id}
                                        />
                                        <DialogFooter>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && (
                                                    <Spinner data-icon="inline-start" />
                                                )}
                                                Link payment
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
