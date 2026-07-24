import { Form, Head } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, ReceiptText } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { store } from '@/actions/App/Http/Controllers/TransactionController';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/transactions';

type Currency = 'USD' | 'PEN';
type TransactionKind = 'purchase' | 'refund';

type LedgerTransaction = {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    kind: TransactionKind;
    merchant_description: string;
    confirmed_at: string;
};

type TransactionsIndexProps = {
    today: string;
    totals: Record<Currency, string>;
    transactions: LedgerTransaction[];
};

type TransactionKindPresentation = {
    label: string;
    icon: LucideIcon;
    badgeVariant: 'outline' | 'secondary';
    amountPrefix: string;
    amountClassName: string;
};

const transactionKindPresentations: Record<
    TransactionKind,
    TransactionKindPresentation
> = {
    purchase: {
        label: 'Purchase',
        icon: ArrowUpRight,
        badgeVariant: 'outline',
        amountPrefix: '',
        amountClassName: '',
    },
    refund: {
        label: 'Refund',
        icon: ArrowDownLeft,
        badgeVariant: 'secondary',
        amountPrefix: '−',
        amountClassName: 'text-emerald-700 dark:text-emerald-400',
    },
};

function NativeSelectField({
    id,
    label,
    defaultValue,
    error,
    options,
}: {
    id: string;
    label: string;
    defaultValue: string;
    error?: string;
    options: ReadonlyArray<{ value: string; label: string }>;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <NativeSelect
                id={id}
                name={id}
                defaultValue={defaultValue}
                aria-invalid={error ? true : undefined}
                options={options}
            />
            <InputError message={error} />
        </div>
    );
}

function formatMinorUnits(amountMinor: string, currency: Currency): string {
    const isNegative = amountMinor.startsWith('-');
    const digits = (isNegative ? amountMinor.slice(1) : amountMinor).padStart(
        3,
        '0',
    );
    const integerPart = digits
        .slice(0, -2)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fractionalPart = digits.slice(-2);
    const symbol = currency === 'USD' ? '$' : 'S/';

    return `${isNegative ? '−' : ''}${symbol} ${integerPart}.${fractionalPart}`;
}

function transactionAmount(transaction: LedgerTransaction): string {
    const formattedAmount = formatMinorUnits(
        transaction.amount_minor,
        transaction.currency,
    );

    return `${transactionKindPresentations[transaction.kind].amountPrefix}${formattedAmount}`;
}

export default function TransactionsIndex({
    today,
    totals,
    transactions,
}: TransactionsIndexProps) {
    return (
        <>
            <Head title="Transactions" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Transactions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Record confirmed purchases and Refunds in their original
                        currency.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {(['USD', 'PEN'] as const).map((currency) => (
                        <Card key={currency} className="gap-3">
                            <CardHeader>
                                <CardDescription>
                                    {currency} net spending
                                </CardDescription>
                                <CardTitle
                                    className="text-3xl tabular-nums"
                                    data-test={`total-${currency.toLowerCase()}`}
                                >
                                    {formatMinorUnits(
                                        totals[currency],
                                        currency,
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-xs text-muted-foreground">
                                    Purchases minus Refunds · {totals[currency]}{' '}
                                    minor units
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="grid items-start gap-6 xl:grid-cols-[minmax(20rem,24rem)_minmax(0,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Record a Transaction</CardTitle>
                            <CardDescription>
                                Saved entries are confirmed and included in
                                totals immediately.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...store.form()}
                                resetOnSuccess={[
                                    'amount_minor',
                                    'merchant_description',
                                ]}
                                className="grid gap-5"
                            >
                                {({
                                    errors,
                                    processing,
                                    recentlySuccessful,
                                }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="occurred_on">
                                                Occurrence date
                                            </Label>
                                            <Input
                                                id="occurred_on"
                                                name="occurred_on"
                                                type="date"
                                                defaultValue={today}
                                                aria-invalid={
                                                    errors.occurred_on
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <InputError
                                                message={errors.occurred_on}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="amount_minor">
                                                Amount in minor units
                                            </Label>
                                            <Input
                                                id="amount_minor"
                                                name="amount_minor"
                                                type="number"
                                                min="1"
                                                step="1"
                                                inputMode="numeric"
                                                placeholder="1250"
                                                aria-describedby="amount_minor_help"
                                                aria-invalid={
                                                    errors.amount_minor
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <p
                                                id="amount_minor_help"
                                                className="text-xs text-muted-foreground"
                                            >
                                                Enter 1250 for 12.50.
                                            </p>
                                            <InputError
                                                message={errors.amount_minor}
                                            />
                                        </div>

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <NativeSelectField
                                                id="currency"
                                                label="Currency"
                                                defaultValue="USD"
                                                error={errors.currency}
                                                options={[
                                                    {
                                                        value: 'USD',
                                                        label: 'USD',
                                                    },
                                                    {
                                                        value: 'PEN',
                                                        label: 'PEN',
                                                    },
                                                ]}
                                            />

                                            <NativeSelectField
                                                id="kind"
                                                label="Transaction kind"
                                                defaultValue="purchase"
                                                error={errors.kind}
                                                options={[
                                                    {
                                                        value: 'purchase',
                                                        label: 'Purchase',
                                                    },
                                                    {
                                                        value: 'refund',
                                                        label: 'Refund',
                                                    },
                                                ]}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="merchant_description">
                                                Merchant or short description
                                            </Label>
                                            <Input
                                                id="merchant_description"
                                                name="merchant_description"
                                                maxLength={255}
                                                placeholder="Neighborhood market"
                                                autoComplete="off"
                                                aria-invalid={
                                                    errors.merchant_description
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors.merchant_description
                                                }
                                            />
                                        </div>

                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            data-test="record-transaction"
                                            className="w-full"
                                        >
                                            {processing && <Spinner />}
                                            Record Transaction
                                        </Button>

                                        {recentlySuccessful && (
                                            <p
                                                role="status"
                                                className="text-center text-sm text-emerald-700 dark:text-emerald-400"
                                            >
                                                Transaction recorded.
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Confirmed ledger</CardTitle>
                            <CardDescription>
                                Latest 100 Transactions, newest occurrence
                                first.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {transactions.length === 0 ? (
                                <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-6 text-center">
                                    <ReceiptText className="size-8 text-muted-foreground" />
                                    <div className="grid gap-1">
                                        <p className="font-medium">
                                            No Transactions yet
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Record the first purchase or Refund
                                            to start the ledger.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[36rem] text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-muted-foreground">
                                                <th className="pb-3 font-medium">
                                                    Date
                                                </th>
                                                <th className="pb-3 font-medium">
                                                    Merchant or description
                                                </th>
                                                <th className="pb-3 font-medium">
                                                    Kind
                                                </th>
                                                <th className="pb-3 text-right font-medium">
                                                    Amount
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {transactions.map((transaction) => {
                                                const presentation =
                                                    transactionKindPresentations[
                                                        transaction.kind
                                                    ];
                                                const KindIcon =
                                                    presentation.icon;

                                                return (
                                                    <tr
                                                        key={transaction.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="py-4 pr-4 whitespace-nowrap text-muted-foreground">
                                                            {
                                                                transaction.occurred_on
                                                            }
                                                        </td>
                                                        <td className="py-4 pr-4 font-medium">
                                                            {
                                                                transaction.merchant_description
                                                            }
                                                        </td>
                                                        <td className="py-4 pr-4">
                                                            <Badge
                                                                variant={
                                                                    presentation.badgeVariant
                                                                }
                                                            >
                                                                <KindIcon />
                                                                {
                                                                    presentation.label
                                                                }
                                                            </Badge>
                                                        </td>
                                                        <td
                                                            className={`py-4 text-right font-medium whitespace-nowrap tabular-nums ${presentation.amountClassName}`}
                                                        >
                                                            {transactionAmount(
                                                                transaction,
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Transactions',
            href: index(),
        },
    ],
};
