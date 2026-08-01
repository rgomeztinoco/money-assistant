import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRightLeft, CalendarDays, PencilLine, Plus } from 'lucide-react';
import {
    retrySeed as retryDailyExchangeRateSeed,
    store as createDailyExchangeRate,
    update as updateDailyExchangeRate,
} from '@/actions/App/Http/Controllers/DailyExchangeRateController';
import { update as updateReportingCurrency } from '@/actions/App/Http/Controllers/ReportingCurrencyController';
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
import { index } from '@/routes/daily_exchange_rates';
import type {
    Currency,
    DailyExchangeRate,
    DailyExchangeRateSeedRequest,
} from '@/types';

type DailyExchangeRatesIndexProps = {
    reporting_currency: Currency | null;
    rates: DailyExchangeRate[];
    seed_requests: DailyExchangeRateSeedRequest[];
    pagination: {
        current_page: number;
        last_page: number;
        previous_page: number | null;
        next_page: number | null;
    };
};

export default function DailyExchangeRatesIndex({
    reporting_currency,
    rates,
    seed_requests,
    pagination,
}: DailyExchangeRatesIndexProps) {
    return (
        <>
            <Head title="Daily Exchange Rates" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <ArrowRightLeft className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Daily Exchange Rates
                        </h1>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Set the Reporting Currency for combined views and keep
                        the PEN value of one USD for each applicable local date.
                        Automatic rates use only the BCRP interbank sell quote.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card
                        id="reporting-currency"
                        className="target:ring-2 target:ring-ring"
                    >
                        <CardHeader>
                            <CardTitle>Reporting Currency</CardTitle>
                            <CardDescription>
                                Changing this re-expresses combined views. It
                                never changes a Transaction.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...updateReportingCurrency.form()}
                                className="flex flex-col gap-4 sm:flex-row sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid flex-1 gap-2">
                                            <Label htmlFor="reporting_currency">
                                                Currency
                                            </Label>
                                            <NativeSelect
                                                id="reporting_currency"
                                                name="reporting_currency"
                                                defaultValue={
                                                    reporting_currency ?? ''
                                                }
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'Choose a currency',
                                                    },
                                                    {
                                                        value: 'USD',
                                                        label: 'USD',
                                                    },
                                                    {
                                                        value: 'PEN',
                                                        label: 'PEN',
                                                    },
                                                ]}
                                                required
                                            />
                                            <InputError
                                                message={
                                                    errors.reporting_currency
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save Reporting Currency
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Add a Daily Exchange Rate</CardTitle>
                            <CardDescription>
                                Enter PEN per USD with up to six decimal places.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...createDailyExchangeRate.form()}
                                resetOnSuccess
                                className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="applicable_on">
                                                Applicable date
                                            </Label>
                                            <Input
                                                id="applicable_on"
                                                name="applicable_on"
                                                type="date"
                                                required
                                            />
                                            <InputError
                                                message={errors.applicable_on}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="pen_per_usd">
                                                PEN per USD
                                            </Label>
                                            <Input
                                                id="pen_per_usd"
                                                name="pen_per_usd"
                                                inputMode="decimal"
                                                placeholder="3.725000"
                                                required
                                            />
                                            <InputError
                                                message={errors.pen_per_usd}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Plus />
                                            )}
                                            Add Rate
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>

                {seed_requests.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Missing rate work</CardTitle>
                            <CardDescription>
                                BCRP retrieval stays separate from dates that
                                now require an owner-entered value.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2">
                            {seed_requests.map((seedRequest) => (
                                <div
                                    key={seedRequest.applicable_on}
                                    id={`rate-request-${seedRequest.applicable_on}`}
                                    className="grid gap-2 rounded-lg border p-4 target:ring-2 target:ring-ring"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-medium tabular-nums">
                                            {seedRequest.applicable_on}
                                        </p>
                                        <Badge
                                            variant={
                                                seedRequest.state === 'pending'
                                                    ? 'secondary'
                                                    : 'destructive'
                                            }
                                        >
                                            {seedRequest.state ===
                                            'owner_entry_required'
                                                ? 'Owner entry required'
                                                : seedRequest.state ===
                                                    'retrieval_failed'
                                                  ? 'BCRP retrieval failed'
                                                  : 'BCRP retrieval pending'}
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {seedRequest.state ===
                                        'owner_entry_required'
                                            ? 'Use the form above to enter PEN per USD for this applicable date.'
                                            : seedRequest.state ===
                                                'retrieval_failed'
                                              ? 'BCRP retrieval failed after bounded retries. Combined reporting remains unavailable until retrieval is retried or you enter a rate.'
                                              : `Attempt ${seedRequest.attempt_count} of 8. The exact BCRP business-day observation may not be published yet.`}
                                    </p>
                                    {seedRequest.state ===
                                        'retrieval_failed' && (
                                        <Form
                                            {...retryDailyExchangeRateSeed.form(
                                                seedRequest.id,
                                            )}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    Retry BCRP retrieval
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Rate Calendar</CardTitle>
                        <CardDescription>
                            Owner-created and edited rates are protected from
                            automatic replacement.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {rates.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-center">
                                <CalendarDays className="size-8 text-muted-foreground" />
                                <p className="font-medium">No rates recorded</p>
                                <p className="max-w-md text-sm text-muted-foreground">
                                    Original USD and PEN totals remain
                                    available. Combined results that need a rate
                                    will show which dates are missing.
                                </p>
                            </div>
                        ) : (
                            rates.map((rate) => (
                                <div
                                    key={rate.id}
                                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-[minmax(9rem,0.7fr)_minmax(0,1fr)] md:items-center"
                                >
                                    <div className="grid gap-1">
                                        <p className="font-medium tabular-nums">
                                            {rate.applicable_on}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary">
                                                {rate.owner_managed
                                                    ? 'Owner managed'
                                                    : rate.source?.label}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                Revision {rate.revision}
                                            </span>
                                        </div>
                                        {rate.source && (
                                            <div className="grid gap-1 pt-2 text-xs text-muted-foreground">
                                                <p>
                                                    {rate.source.attribution},{' '}
                                                    BCRPData series{' '}
                                                    {rate.source.series}
                                                </p>
                                                <p>
                                                    Applicable date:{' '}
                                                    {rate.applicable_on}
                                                </p>
                                                <p>
                                                    Source observation date:{' '}
                                                    {rate.source.observed_on}
                                                </p>
                                                <p>
                                                    Retrieved:{' '}
                                                    {rate.source.retrieved_at}
                                                </p>
                                                <p>
                                                    Source value:{' '}
                                                    {rate.source.value}{' '}
                                                    (declared precision:{' '}
                                                    {rate.source.precision}{' '}
                                                    decimal places)
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    <Form
                                        {...updateDailyExchangeRate.form(
                                            rate.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                        className="flex flex-col gap-3 sm:flex-row sm:items-end"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="expected_revision"
                                                    value={rate.revision}
                                                />
                                                <div className="grid flex-1 gap-2">
                                                    <Label
                                                        htmlFor={`rate-${rate.id}`}
                                                    >
                                                        PEN per USD
                                                    </Label>
                                                    <Input
                                                        key={`${rate.id}-${rate.revision}`}
                                                        id={`rate-${rate.id}`}
                                                        name="pen_per_usd"
                                                        defaultValue={
                                                            rate.pen_per_usd
                                                        }
                                                        inputMode="decimal"
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.pen_per_usd ??
                                                            errors.expected_revision
                                                        }
                                                    />
                                                </div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {processing ? (
                                                        <Spinner />
                                                    ) : (
                                                        <PencilLine />
                                                    )}
                                                    Update
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </div>
                            ))
                        )}
                        {pagination.last_page > 1 && (
                            <div className="flex items-center justify-between gap-3 border-t pt-4">
                                <div>
                                    {pagination.previous_page !== null && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={index({
                                                    query: {
                                                        rates_page:
                                                            pagination.previous_page,
                                                    },
                                                })}
                                                preserveScroll
                                            >
                                                Previous
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                                <span className="text-xs text-muted-foreground">
                                    Page {pagination.current_page} of{' '}
                                    {pagination.last_page}
                                </span>
                                <div>
                                    {pagination.next_page !== null && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={index({
                                                    query: {
                                                        rates_page:
                                                            pagination.next_page,
                                                    },
                                                })}
                                                preserveScroll
                                            >
                                                Next
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

DailyExchangeRatesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Daily Exchange Rates',
            href: index(),
        },
    ],
};
