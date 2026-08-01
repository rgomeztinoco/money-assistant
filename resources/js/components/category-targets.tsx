import { Form } from '@inertiajs/react';
import {
    Archive,
    CircleDollarSign,
    PencilLine,
    Plus,
    Target,
} from 'lucide-react';
import { useState } from 'react';
import {
    store as createCategoryTarget,
    update as reviseCategoryTarget,
} from '@/actions/App/Http/Controllers/CategoryTargetController';
import retireCategoryTarget from '@/actions/App/Http/Controllers/CategoryTargetRetirementController';
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
import { formatMinorUnits } from '@/lib/format-minor-units';
import type { Currency } from '@/types';

export type TargetOption = {
    category: { id: number; name: string };
    baseline_prefill: {
        currency: Currency;
        amount_minor: string;
    } | null;
};

type CategoryTargetProgress = {
    spent_minor: string | null;
    remaining_minor: string | null;
    percentage_basis_points: string | null;
    state: 'remaining' | 'met' | 'exceeded' | 'unavailable';
    period_status: 'completed' | 'to_date';
    unavailable_reason: 'missing_exchange_rates' | null;
    missing_rate_dates: string[];
};

export type CategoryTarget = {
    id: number;
    revision: number;
    category: { id: number; name: string };
    currency: Currency;
    starts_on: string;
    effective_month: string | null;
    status: 'active' | 'scheduled' | 'retired';
    amount_minor: string | null;
    progress: CategoryTargetProgress | null;
};

type CategoryTargetsProps = {
    baselineStatus: 'unavailable' | 'provisional' | 'established';
    defaultEffectiveMonth: string;
    targetOptions: TargetOption[];
    targets: CategoryTarget[];
};

function decimalFromMinorUnits(amountMinor: string): string {
    const digits = amountMinor.padStart(3, '0');

    return `${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

function decimalToMinorUnits(amount: string): string {
    const match = /^(\d+)(?:\.(\d{0,2}))?$/.exec(amount.trim());

    if (!match) {
        return '';
    }

    const major = match[1].replace(/^0+(?=\d)/, '');

    return `${major}${(match[2] ?? '').padEnd(2, '0')}`.replace(
        /^0+(?=\d)/,
        '',
    );
}

function monthInputValue(date: string): string {
    return date.slice(0, 7);
}

function earliestEffectiveMonth(
    target: CategoryTarget,
    defaultEffectiveMonth: string,
): string {
    return target.starts_on > defaultEffectiveMonth
        ? target.starts_on
        : defaultEffectiveMonth;
}

function formatUsedPercentage(basisPoints: string): string {
    const isNegative = basisPoints.startsWith('-');
    const digits = (isNegative ? basisPoints.slice(1) : basisPoints).padStart(
        3,
        '0',
    );

    return `${isNegative ? '−' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}%`;
}

function AmountField({
    id,
    amount,
    currency,
    onChange,
}: {
    id: string;
    amount: string;
    currency: Currency;
    onChange: (amount: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Monthly amount ({currency})</Label>
            <Input
                id={id}
                type="text"
                inputMode="decimal"
                value={amount}
                onChange={(event) => onChange(event.target.value)}
                placeholder="0.00"
                required
            />
            <input
                type="hidden"
                name="amount_minor"
                value={decimalToMinorUnits(amount)}
            />
        </div>
    );
}

function CreateTargetForm({
    options,
    defaultEffectiveMonth,
}: {
    options: TargetOption[];
    defaultEffectiveMonth: string;
}) {
    const [categoryId, setCategoryId] = useState(options[0].category.id);
    const [currency, setCurrency] = useState<Currency>(
        options[0].baseline_prefill?.currency ?? 'PEN',
    );
    const [amount, setAmount] = useState(
        options[0].baseline_prefill
            ? decimalFromMinorUnits(options[0].baseline_prefill.amount_minor)
            : '',
    );
    const [startsOn, setStartsOn] = useState(
        monthInputValue(defaultEffectiveMonth),
    );

    function chooseCategory(value: string) {
        const selected = options.find(
            (option) => option.category.id === Number(value),
        );

        if (!selected) {
            return;
        }

        setCategoryId(selected.category.id);
        setCurrency(selected.baseline_prefill?.currency ?? 'PEN');
        setAmount(
            selected.baseline_prefill
                ? decimalFromMinorUnits(selected.baseline_prefill.amount_minor)
                : '',
        );
    }

    return (
        <Form
            {...createCategoryTarget.form()}
            options={{ preserveScroll: true }}
            className="grid gap-4 rounded-lg border p-4 lg:grid-cols-4 lg:items-end"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="target-category">Category</Label>
                        <NativeSelect
                            id="target-category"
                            name="category_id"
                            value={categoryId.toString()}
                            onChange={(event) =>
                                chooseCategory(event.target.value)
                            }
                            options={options.map((option) => ({
                                value: option.category.id.toString(),
                                label: option.category.name,
                            }))}
                        />
                    </div>
                    <AmountField
                        id="target-amount"
                        amount={amount}
                        currency={currency}
                        onChange={setAmount}
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="target-currency">Currency</Label>
                        <NativeSelect
                            id="target-currency"
                            name="currency"
                            value={currency}
                            onChange={(event) => {
                                const selectedCurrency = event.target
                                    .value as Currency;
                                const baselineCurrency = options.find(
                                    (option) =>
                                        option.category.id === categoryId,
                                )?.baseline_prefill?.currency;

                                if (selectedCurrency !== baselineCurrency) {
                                    setAmount('');
                                }

                                setCurrency(selectedCurrency);
                            }}
                            options={[
                                { value: 'USD', label: 'USD' },
                                { value: 'PEN', label: 'PEN' },
                            ]}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="target-starts-on">Starting month</Label>
                        <Input
                            id="target-starts-on"
                            type="month"
                            min={monthInputValue(defaultEffectiveMonth)}
                            value={startsOn}
                            onChange={(event) =>
                                setStartsOn(event.target.value)
                            }
                            required
                        />
                        <input
                            type="hidden"
                            name="starts_on"
                            value={startsOn ? `${startsOn}-01` : ''}
                        />
                    </div>
                    <InputError
                        className="lg:col-span-3"
                        message={
                            errors.category_id ??
                            errors.amount_minor ??
                            errors.currency ??
                            errors.starts_on
                        }
                    />
                    <Button type="submit" disabled={processing}>
                        {processing ? <Spinner /> : <Plus />}
                        Approve Target
                    </Button>
                </>
            )}
        </Form>
    );
}

function ReviseTargetDialog({
    target,
    defaultEffectiveMonth,
}: {
    target: CategoryTarget;
    defaultEffectiveMonth: string;
}) {
    const [open, setOpen] = useState(false);
    const minimumEffectiveMonth = earliestEffectiveMonth(
        target,
        defaultEffectiveMonth,
    );
    const [amount, setAmount] = useState(
        target.amount_minor === null
            ? ''
            : decimalFromMinorUnits(target.amount_minor),
    );
    const [effectiveMonth, setEffectiveMonth] = useState(
        monthInputValue(minimumEffectiveMonth),
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <PencilLine />
                    {target.status === 'retired' ? 'Reactivate' : 'Revise'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {target.status === 'retired' ? 'Reactivate' : 'Revise'}{' '}
                        {target.category.name}
                    </DialogTitle>
                    <DialogDescription>
                        The change applies from the selected current or future
                        month. Completed results retain their earlier amount.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...reviseCategoryTarget.form(target.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="expected_revision"
                                value={target.revision}
                            />
                            <AmountField
                                id={`target-${target.id}-amount`}
                                amount={amount}
                                currency={target.currency}
                                onChange={setAmount}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor={`target-${target.id}-month`}>
                                    Effective month
                                </Label>
                                <Input
                                    id={`target-${target.id}-month`}
                                    type="month"
                                    min={monthInputValue(minimumEffectiveMonth)}
                                    value={effectiveMonth}
                                    onChange={(event) =>
                                        setEffectiveMonth(event.target.value)
                                    }
                                    required
                                />
                                <input
                                    type="hidden"
                                    name="effective_month"
                                    value={
                                        effectiveMonth
                                            ? `${effectiveMonth}-01`
                                            : ''
                                    }
                                />
                            </div>
                            <InputError
                                message={
                                    errors.amount_minor ??
                                    errors.effective_month ??
                                    errors.expected_revision
                                }
                            />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Save revision
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function RetireTargetDialog({
    target,
    defaultEffectiveMonth,
}: {
    target: CategoryTarget;
    defaultEffectiveMonth: string;
}) {
    const [open, setOpen] = useState(false);
    const minimumEffectiveMonth = earliestEffectiveMonth(
        target,
        defaultEffectiveMonth,
    );
    const [effectiveMonth, setEffectiveMonth] = useState(
        monthInputValue(minimumEffectiveMonth),
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="secondary">
                    <Archive /> Retire
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Retire {target.category.name}</DialogTitle>
                    <DialogDescription>
                        Retirement applies from a current or future month.
                        Completed results remain visible.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...retireCategoryTarget.form(target.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="expected_revision"
                                value={target.revision}
                            />
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`target-${target.id}-retirement-month`}
                                >
                                    Retirement month
                                </Label>
                                <Input
                                    id={`target-${target.id}-retirement-month`}
                                    type="month"
                                    min={monthInputValue(minimumEffectiveMonth)}
                                    value={effectiveMonth}
                                    onChange={(event) =>
                                        setEffectiveMonth(event.target.value)
                                    }
                                    required
                                />
                                <input
                                    type="hidden"
                                    name="effective_month"
                                    value={
                                        effectiveMonth
                                            ? `${effectiveMonth}-01`
                                            : ''
                                    }
                                />
                            </div>
                            <InputError
                                message={
                                    errors.effective_month ??
                                    errors.expected_revision
                                }
                            />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Schedule retirement
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function TargetProgress({ target }: { target: CategoryTarget }) {
    const progress = target.progress;

    if (target.status === 'scheduled') {
        return (
            <p className="text-sm text-muted-foreground">
                Starts in {target.starts_on.slice(0, 7)}. Progress begins in the
                selected starting month.
            </p>
        );
    }

    if (target.status === 'retired' || progress === null) {
        return (
            <p className="text-sm text-muted-foreground">
                Retired from {target.effective_month?.slice(0, 7)}. Earlier
                months retain their results.
            </p>
        );
    }

    if (progress.spent_minor === null || progress.remaining_minor === null) {
        return (
            <p className="text-sm text-muted-foreground">
                Progress is unavailable until the affected Daily Exchange Rates
                are complete.
            </p>
        );
    }

    const remainingAbsolute = progress.remaining_minor.startsWith('-')
        ? progress.remaining_minor.slice(1)
        : progress.remaining_minor;

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            <div>
                <p className="text-xs text-muted-foreground">Spent</p>
                <p className="font-semibold tabular-nums">
                    {formatMinorUnits(progress.spent_minor, target.currency)}
                </p>
            </div>
            <div>
                <p className="text-xs text-muted-foreground">
                    {progress.state === 'exceeded'
                        ? 'Exceeded by'
                        : 'Remaining'}
                </p>
                <p className="font-semibold tabular-nums">
                    {formatMinorUnits(remainingAbsolute, target.currency)}
                </p>
            </div>
            {progress.percentage_basis_points !== null && (
                <div>
                    <p className="text-xs text-muted-foreground">Used</p>
                    <p className="font-semibold tabular-nums">
                        {formatUsedPercentage(progress.percentage_basis_points)}
                    </p>
                </div>
            )}
        </div>
    );
}

export default function CategoryTargets({
    baselineStatus,
    defaultEffectiveMonth,
    targetOptions,
    targets,
}: CategoryTargetsProps) {
    return (
        <Card id="targets">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <Target className="size-5 text-muted-foreground" />
                        <CardTitle>Category Targets</CardTitle>
                    </div>
                    <Badge variant="outline">Owner approved</Badge>
                </div>
                <CardDescription>
                    Recurring monthly intentions with factual progress. Baseline
                    amounts are context only and never activate a Target or
                    infer a reduction.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-5">
                {baselineStatus !== 'established' ? (
                    <div className="flex items-start gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        <CircleDollarSign className="mt-0.5 size-4 shrink-0" />
                        Three complete reviewed months are required before a
                        Category Target can be created.
                    </div>
                ) : targetOptions.length > 0 ? (
                    <div className="grid gap-3">
                        <div>
                            <p className="text-sm font-medium">
                                Approve a Category Target
                            </p>
                            <p className="text-xs text-muted-foreground">
                                The baseline average may prefill the amount.
                                Review the amount, currency, and starting month
                                before approval.
                            </p>
                        </div>
                        <CreateTargetForm
                            options={targetOptions}
                            defaultEffectiveMonth={defaultEffectiveMonth}
                        />
                    </div>
                ) : targets.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Create an active Category before approving a Target.
                    </p>
                ) : null}

                {targets.length > 0 && (
                    <div className="grid gap-3">
                        {targets.map((target) => (
                            <div
                                key={target.id}
                                className="grid gap-4 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">
                                                {target.category.name}
                                            </p>
                                            <Badge
                                                variant={
                                                    target.status === 'active'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {target.status === 'active'
                                                    ? target.progress
                                                          ?.period_status ===
                                                      'completed'
                                                        ? 'Completed result'
                                                        : 'To date'
                                                    : target.status ===
                                                        'scheduled'
                                                      ? 'Scheduled'
                                                      : 'Retired'}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {target.amount_minor === null
                                                ? `Fixed in ${target.currency}`
                                                : `${formatMinorUnits(target.amount_minor, target.currency)} each month · fixed in ${target.currency}`}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <ReviseTargetDialog
                                            target={target}
                                            defaultEffectiveMonth={
                                                defaultEffectiveMonth
                                            }
                                        />
                                        {target.status !== 'retired' && (
                                            <RetireTargetDialog
                                                target={target}
                                                defaultEffectiveMonth={
                                                    defaultEffectiveMonth
                                                }
                                            />
                                        )}
                                    </div>
                                </div>
                                <TargetProgress target={target} />
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
