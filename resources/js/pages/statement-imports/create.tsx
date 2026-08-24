import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowLeft,
    ArrowUpRight,
    CheckCircle2,
    CircleAlert,
    FileCheck2,
    PencilLine,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { store as confirmStatementImport } from '@/actions/App/Http/Controllers/StatementImportController';
import { store as previewStatementImport } from '@/actions/App/Http/Controllers/StatementImportPreviewController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
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
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    statementMovementClassificationOptions,
    statementMovementContributesToSpending,
} from '@/lib/statement-movement-classification';
import { index } from '@/routes/statement_imports';
import type {
    StatementClassification,
    StatementImportPreview,
    StatementPreviewMovement,
} from '@/types';

type ConfirmationMovement = Pick<
    StatementPreviewMovement,
    | 'source_row_id'
    | 'occurred_on'
    | 'description'
    | 'amount_minor'
    | 'currency'
    | 'classification'
>;

type ConfirmationData = {
    statement: File | null;
    file_hash: string;
    instrument_label: string;
    instrument_last_four: string;
    movements: ConfirmationMovement[];
};

type MovementEditorProps = {
    movement: ConfirmationMovement;
    movementIndex: number;
    sourceMovement: StatementPreviewMovement;
    updateMovement: (
        movementIndex: number,
        values: Partial<ConfirmationMovement>,
    ) => void;
    movementError: (
        movementIndex: number,
        field: keyof ConfirmationMovement,
    ) => string | undefined;
};

function humanizeKey(key: string): string {
    return key.replaceAll('_minor', '').replaceAll('_', ' ');
}

function reconciliationCurrency(key: string): 'PEN' | 'USD' {
    return key.includes('_usd_') ? 'USD' : 'PEN';
}

function parseStatementClassification(
    value: string,
): StatementClassification | null {
    return (
        statementMovementClassificationOptions.find(
            (classification) => classification.value === value,
        )?.value ?? null
    );
}

function hasOwnProperty<Key extends PropertyKey>(
    value: object,
    key: Key,
): value is object & Record<Key, unknown> {
    return Object.hasOwn(value, key);
}

function MovementEditor({
    movement,
    movementIndex,
    sourceMovement,
    updateMovement,
    movementError,
}: MovementEditorProps) {
    const classificationOptions = statementMovementClassificationOptions.filter(
        (option) =>
            option.value !== 'not_a_movement' || sourceMovement.can_be_excluded,
    );
    const isMoneyIn = sourceMovement.direction === 'credit';
    const isUnresolved = movement.classification === 'needs_classification';
    const affectsNetSpending = statementMovementContributesToSpending(
        movement.classification,
    );
    const netSpendingStatus = affectsNetSpending
        ? 'Affects Net Spending'
        : 'Outside Net Spending';
    const statusBadgeVariant = isUnresolved
        ? 'destructive'
        : affectsNetSpending
          ? 'default'
          : 'secondary';
    const DirectionIcon = isMoneyIn ? ArrowDownLeft : ArrowUpRight;
    const classificationError = movementError(movementIndex, 'classification');

    return (
        <Dialog>
            <TableRow
                className={
                    isUnresolved
                        ? 'bg-destructive/5 hover:bg-destructive/10'
                        : undefined
                }
                data-test={`statement-movement-${movementIndex}`}
            >
                <TableCell className="pl-6">
                    <span
                        className={`flex size-9 items-center justify-center rounded-full ${isMoneyIn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}
                    >
                        <DirectionIcon className="size-4" />
                        <span className="sr-only">
                            {isMoneyIn ? 'Money in' : 'Money out'}
                        </span>
                    </span>
                </TableCell>
                <TableCell className="max-w-72 min-w-52 whitespace-normal">
                    <div className="grid gap-1">
                        <span className="font-medium break-words">
                            {movement.description}
                        </span>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {movement.occurred_on}
                        </span>
                    </div>
                </TableCell>
                <TableCell
                    className={`text-right font-semibold tabular-nums ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                >
                    {isMoneyIn ? '+' : '−'}
                    {formatMinorUnits(
                        movement.amount_minor || '0',
                        movement.currency,
                    )}
                </TableCell>
                <TableCell className="min-w-52 whitespace-normal">
                    <Label
                        htmlFor={`movement-${movementIndex}-classification`}
                        className="sr-only"
                    >
                        Categorization for {movement.description}
                    </Label>
                    <NativeSelect
                        id={`movement-${movementIndex}-classification`}
                        aria-label={`Classification for ${movement.description}`}
                        value={movement.classification}
                        onChange={(event) => {
                            const classification = parseStatementClassification(
                                event.currentTarget.value,
                            );

                            if (classification !== null) {
                                updateMovement(movementIndex, {
                                    classification,
                                });
                            }
                        }}
                        options={classificationOptions}
                        aria-invalid={Boolean(classificationError)}
                        aria-describedby={
                            classificationError
                                ? `movement-${movementIndex}-classification-error`
                                : undefined
                        }
                    />
                    <InputError
                        id={`movement-${movementIndex}-classification-error`}
                        message={classificationError}
                        className="mt-1"
                    />
                </TableCell>
                <TableCell className="min-w-44 whitespace-normal">
                    <div
                        className="flex flex-col items-start gap-2"
                        data-test={`statement-movement-status-${movementIndex}`}
                    >
                        <Badge variant={statusBadgeVariant} className="w-fit">
                            {isUnresolved && <CircleAlert />}
                            {isUnresolved ? 'Unresolved' : netSpendingStatus}
                        </Badge>
                    </div>
                </TableCell>
                <TableCell className="pr-6 text-right">
                    <DialogTrigger asChild>
                        <Button type="button" variant="outline" size="sm">
                            <PencilLine /> Edit
                        </Button>
                    </DialogTrigger>
                </TableCell>
            </TableRow>
            <DialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Edit Statement Movement</DialogTitle>
                    <DialogDescription>
                        Edit the movement details below. Change its
                        categorization directly in the Movements table.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label
                            htmlFor={`movement-${movementIndex}-occurred-on`}
                        >
                            Date
                        </Label>
                        <Input
                            id={`movement-${movementIndex}-occurred-on`}
                            type="date"
                            value={movement.occurred_on}
                            onChange={(event) =>
                                updateMovement(movementIndex, {
                                    occurred_on: event.currentTarget.value,
                                })
                            }
                            aria-invalid={Boolean(
                                movementError(movementIndex, 'occurred_on'),
                            )}
                            aria-describedby={`movement-${movementIndex}-occurred-on-error`}
                            required
                        />
                        <InputError
                            id={`movement-${movementIndex}-occurred-on-error`}
                            message={movementError(
                                movementIndex,
                                'occurred_on',
                            )}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label
                            htmlFor={`movement-${movementIndex}-description`}
                        >
                            Description
                        </Label>
                        <Input
                            id={`movement-${movementIndex}-description`}
                            value={movement.description}
                            maxLength={255}
                            onChange={(event) =>
                                updateMovement(movementIndex, {
                                    description: event.currentTarget.value,
                                })
                            }
                            aria-invalid={Boolean(
                                movementError(movementIndex, 'description'),
                            )}
                            aria-describedby={`movement-${movementIndex}-description-error`}
                            required
                        />
                        <InputError
                            id={`movement-${movementIndex}-description-error`}
                            message={movementError(
                                movementIndex,
                                'description',
                            )}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`movement-${movementIndex}-amount`}>
                            Amount in minor units
                        </Label>
                        <Input
                            id={`movement-${movementIndex}-amount`}
                            value={movement.amount_minor}
                            inputMode="numeric"
                            pattern="\d+"
                            onChange={(event) =>
                                updateMovement(movementIndex, {
                                    amount_minor: event.currentTarget.value,
                                })
                            }
                            aria-invalid={Boolean(
                                movementError(movementIndex, 'amount_minor'),
                            )}
                            aria-describedby={`movement-${movementIndex}-amount-error movement-${movementIndex}-formatted-amount`}
                            required
                        />
                        <InputError
                            id={`movement-${movementIndex}-amount-error`}
                            message={movementError(
                                movementIndex,
                                'amount_minor',
                            )}
                        />
                        <p
                            id={`movement-${movementIndex}-formatted-amount`}
                            className="text-xs text-muted-foreground"
                        >
                            {formatMinorUnits(
                                movement.amount_minor || '0',
                                movement.currency,
                            )}
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`movement-${movementIndex}-currency`}>
                            Currency
                        </Label>
                        <NativeSelect
                            id={`movement-${movementIndex}-currency`}
                            value={movement.currency}
                            aria-invalid={Boolean(
                                movementError(movementIndex, 'currency'),
                            )}
                            aria-describedby={`movement-${movementIndex}-currency-error`}
                            onChange={(event) => {
                                const currency = event.currentTarget.value;

                                if (currency === 'PEN' || currency === 'USD') {
                                    updateMovement(movementIndex, {
                                        currency,
                                    });
                                }
                            }}
                            options={[
                                { value: 'PEN', label: 'PEN' },
                                { value: 'USD', label: 'USD' },
                            ]}
                        />
                        <InputError
                            id={`movement-${movementIndex}-currency-error`}
                            message={movementError(movementIndex, 'currency')}
                        />
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default function CreateStatementImport() {
    const previewRequest = useHttp<
        { statement: File | null },
        StatementImportPreview
    >({ statement: null });
    const confirmation = useForm<ConfirmationData>({
        statement: null,
        file_hash: '',
        instrument_label: '',
        instrument_last_four: '',
        movements: [],
    });
    const [preview, setPreview] = useState<StatementImportPreview | null>(null);
    const [selectedStatement, setSelectedStatement] = useState<File | null>(
        null,
    );
    const unresolvedMovementIndexes = confirmation.data.movements
        .map((movement, movementIndex) => ({ movement, movementIndex }))
        .filter(
            ({ movement }) =>
                movement.classification === 'needs_classification',
        )
        .map(({ movementIndex }) => movementIndex);
    const unresolvedCount = unresolvedMovementIndexes.length;
    const spendingMovementCount = confirmation.data.movements.filter(
        (movement) =>
            statementMovementContributesToSpending(movement.classification),
    ).length;
    const outsideNetSpendingCount =
        confirmation.data.movements.length -
        spendingMovementCount -
        unresolvedCount;
    function requestPreview(event: React.FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (selectedStatement === null) {
            return;
        }

        setPreview(null);
        confirmation.reset();
        confirmation.clearErrors();
        previewRequest.transform((data) => ({
            ...data,
            statement: selectedStatement,
        }));
        previewRequest.post(previewStatementImport.url(), {
            onSuccess: (response) => {
                setPreview(response);
                confirmation.setData({
                    statement: selectedStatement,
                    ...response.confirmation,
                    instrument_last_four:
                        response.confirmation.instrument_last_four ?? '',
                });
            },
        });
    }

    function updateMovement(
        movementIndex: number,
        values: Partial<ConfirmationMovement>,
    ): void {
        confirmation.clearErrors();
        confirmation.setData(
            'movements',
            confirmation.data.movements.map((movement, currentIndex) =>
                currentIndex === movementIndex
                    ? { ...movement, ...values }
                    : movement,
            ),
        );
    }

    function movementError(
        movementIndex: number,
        field: keyof ConfirmationMovement,
    ): string | undefined {
        const errors: object = confirmation.errors;
        const errorKey = `movements.${movementIndex}.${field}`;

        if (!hasOwnProperty(errors, errorKey)) {
            return undefined;
        }

        const error = errors[errorKey];

        return typeof error === 'string' ? error : undefined;
    }

    function confirm(event: React.FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (selectedStatement === null || unresolvedCount > 0) {
            return;
        }

        confirmation.transform((data) => ({
            ...data,
            statement: selectedStatement,
        }));
        confirmation.post(confirmStatementImport.url());
    }

    return (
        <>
            <Head title="Import a statement" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Import a statement
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Choose a supported BCP or Interbank text PDF once,
                            then resolve only the movements that need attention.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={index()}>
                            <ArrowLeft /> Statement Imports
                        </Link>
                    </Button>
                </div>

                <div className="grid min-h-0 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-stretch">
                    <div
                        className="grid min-w-0 gap-4 lg:grid-cols-2"
                        data-test="statement-import-overview"
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>1. Choose PDF</CardTitle>
                                <CardDescription>
                                    The file and extracted text stay transient.
                                    Refreshing or leaving this page discards
                                    them.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={requestPreview}
                                    className="grid gap-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="preview-statement">
                                            Statement PDF
                                        </Label>
                                        <Input
                                            id="preview-statement"
                                            name="statement"
                                            type="file"
                                            accept="application/pdf,.pdf"
                                            onChange={(event) => {
                                                const statement =
                                                    event.currentTarget
                                                        .files?.[0] ?? null;

                                                setPreview(null);
                                                setSelectedStatement(statement);
                                                previewRequest.clearErrors(
                                                    'statement',
                                                );
                                                previewRequest.setData(
                                                    'statement',
                                                    statement,
                                                );
                                            }}
                                            required
                                        />
                                        <InputError
                                            message={
                                                previewRequest.errors.statement
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            previewRequest.processing ||
                                            selectedStatement === null
                                        }
                                    >
                                        {previewRequest.processing ? (
                                            <Spinner />
                                        ) : (
                                            <Upload />
                                        )}
                                        Preview statement
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        {preview && (
                            <>
                                <Card data-test="statement-checks">
                                    <CardHeader>
                                        <CardTitle>Reconciliation</CardTitle>
                                        <CardDescription>
                                            Printed statement totals verified
                                            against every parsed Statement
                                            Movement.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-6">
                                        <section className="grid gap-3">
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {Object.entries(
                                                    preview.reconciliation,
                                                ).map(([key, value]) => {
                                                    const currency =
                                                        reconciliationCurrency(
                                                            key,
                                                        );

                                                    return (
                                                        <div
                                                            key={key}
                                                            className="grid gap-1 rounded-lg border p-3"
                                                        >
                                                            <span className="text-xs text-muted-foreground capitalize">
                                                                {humanizeKey(
                                                                    key,
                                                                )}
                                                            </span>
                                                            <span className="font-medium tabular-nums">
                                                                {formatMinorUnits(
                                                                    value,
                                                                    currency,
                                                                )}
                                                            </span>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </section>

                                        {preview.informational_values.length >
                                            0 && (
                                            <section className="grid gap-3">
                                                <div className="grid gap-1">
                                                    <h2 className="font-semibold">
                                                        Informational values
                                                    </h2>
                                                    <p className="text-sm text-muted-foreground">
                                                        These values support
                                                        review but are not
                                                        posted movements.
                                                    </p>
                                                </div>
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    {preview.informational_values.map(
                                                        (value, valueIndex) => (
                                                            <div
                                                                key={`${value.label}-${valueIndex}`}
                                                                className="grid gap-1 rounded-lg border p-3"
                                                            >
                                                                <span className="text-xs text-muted-foreground">
                                                                    {
                                                                        value.label
                                                                    }
                                                                </span>
                                                                <span className="font-medium tabular-nums">
                                                                    {formatMinorUnits(
                                                                        value.value,
                                                                        value.currency,
                                                                    )}
                                                                </span>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </section>
                                        )}
                                    </CardContent>
                                </Card>

                                <form
                                    onSubmit={confirm}
                                    className="col-span-full"
                                >
                                    <Card>
                                        <CardHeader>
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="grid gap-1">
                                                    <CardTitle>
                                                        Proposed import
                                                    </CardTitle>
                                                    <CardDescription>
                                                        {preview.period_start}{' '}
                                                        through{' '}
                                                        {preview.period_end}
                                                    </CardDescription>
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <Badge variant="outline">
                                                        {preview.financial_statement_format.toUpperCase()}
                                                    </Badge>
                                                    <Badge variant="secondary">
                                                        <FileCheck2 />{' '}
                                                        Reconciled
                                                    </Badge>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="grid gap-5">
                                            <div className="grid grid-cols-4 gap-3">
                                                <div className="grid gap-1 rounded-lg border p-3">
                                                    <span className="text-xs text-muted-foreground">
                                                        Proposed movements
                                                    </span>
                                                    <span className="text-xl font-semibold tabular-nums">
                                                        {
                                                            confirmation.data
                                                                .movements
                                                                .length
                                                        }
                                                    </span>
                                                </div>
                                                <div className="grid gap-1 rounded-lg border p-3">
                                                    <span className="text-xs text-muted-foreground">
                                                        Affect Net Spending
                                                    </span>
                                                    <span className="text-xl font-semibold tabular-nums">
                                                        {spendingMovementCount}
                                                    </span>
                                                </div>
                                                <div className="grid gap-1 rounded-lg border p-3">
                                                    <span className="text-xs text-muted-foreground">
                                                        Outside Net Spending
                                                    </span>
                                                    <span className="text-xl font-semibold tabular-nums">
                                                        {
                                                            outsideNetSpendingCount
                                                        }
                                                    </span>
                                                </div>
                                                <div className="grid gap-1 rounded-lg border p-3">
                                                    <span className="text-xs text-muted-foreground">
                                                        Unresolved
                                                    </span>
                                                    <span className="text-xl font-semibold tabular-nums">
                                                        {unresolvedCount}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="grid gap-4 md:grid-cols-3">
                                                <div className="grid gap-2 md:col-span-2">
                                                    <Label htmlFor="instrument-label">
                                                        Safe instrument label
                                                    </Label>
                                                    <Input
                                                        id="instrument-label"
                                                        value={
                                                            confirmation.data
                                                                .instrument_label
                                                        }
                                                        maxLength={100}
                                                        onChange={(event) => {
                                                            confirmation.clearErrors(
                                                                'instrument_label',
                                                            );
                                                            confirmation.setData(
                                                                'instrument_label',
                                                                event
                                                                    .currentTarget
                                                                    .value,
                                                            );
                                                        }}
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            confirmation.errors
                                                                .instrument_label
                                                        }
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="instrument-last-four">
                                                        Last four digits
                                                    </Label>
                                                    <Input
                                                        id="instrument-last-four"
                                                        value={
                                                            confirmation.data
                                                                .instrument_last_four
                                                        }
                                                        inputMode="numeric"
                                                        pattern="\d{4}"
                                                        maxLength={4}
                                                        onChange={(event) => {
                                                            confirmation.clearErrors(
                                                                'instrument_last_four',
                                                            );
                                                            confirmation.setData(
                                                                'instrument_last_four',
                                                                event
                                                                    .currentTarget
                                                                    .value,
                                                            );
                                                        }}
                                                    />
                                                    <InputError
                                                        message={
                                                            confirmation.errors
                                                                .instrument_last_four
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            {Object.keys(confirmation.errors)
                                                .length > 0 && (
                                                <AlertError
                                                    title="The Statement Import was not confirmed."
                                                    errors={Object.values(
                                                        confirmation.errors,
                                                    )}
                                                />
                                            )}
                                        </CardContent>
                                        <Separator />
                                        <CardFooter className="flex-col items-stretch justify-between gap-4 sm:flex-row sm:items-center">
                                            <div className="grid gap-1">
                                                <p className="font-medium">
                                                    {unresolvedCount === 0
                                                        ? 'Ready for confirmation'
                                                        : `${unresolvedCount} movement${unresolvedCount === 1 ? '' : 's'} still need a decision`}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    The PDF remains in this
                                                    tab's memory. Confirmation
                                                    reparses it and checks every
                                                    reconciliation invariant.
                                                </p>
                                            </div>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    confirmation.processing ||
                                                    selectedStatement ===
                                                        null ||
                                                    unresolvedCount > 0
                                                }
                                            >
                                                {confirmation.processing && (
                                                    <Spinner />
                                                )}
                                                Confirm Statement Import
                                            </Button>
                                        </CardFooter>
                                    </Card>
                                </form>
                            </>
                        )}
                    </div>

                    {preview && (
                        <div
                            className="min-w-0 lg:relative lg:min-h-0"
                            data-test="statement-movements-column"
                        >
                            <Card className="min-w-0 lg:absolute lg:inset-0 lg:min-h-0">
                                <CardHeader className="shrink-0">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="grid gap-1">
                                            <CardTitle>2. Movements</CardTitle>
                                            <CardDescription>
                                                Change categorization in the
                                                table, or edit the remaining
                                                details from each row.
                                            </CardDescription>
                                        </div>
                                        <Badge
                                            variant={
                                                unresolvedCount > 0
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {unresolvedCount > 0 ? (
                                                <CircleAlert />
                                            ) : (
                                                <CheckCircle2 />
                                            )}
                                            {unresolvedCount} unresolved
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent
                                    className="min-w-0 p-0 lg:min-h-0 lg:flex-1 lg:[&>[data-slot=table-container]]:h-full lg:[&>[data-slot=table-container]]:overflow-auto"
                                    data-test="statement-movements"
                                >
                                    <Table className="min-w-[54rem]">
                                        <TableHeader className="sticky top-0 z-10 bg-card">
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="w-14 px-6">
                                                    <span className="sr-only">
                                                        Direction
                                                    </span>
                                                </TableHead>
                                                <TableHead>Movement</TableHead>
                                                <TableHead className="text-right">
                                                    Amount
                                                </TableHead>
                                                <TableHead>
                                                    Categorization
                                                </TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="pr-6 text-right">
                                                    Actions
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {confirmation.data.movements.map(
                                                (movement, movementIndex) => (
                                                    <MovementEditor
                                                        key={
                                                            movement.source_row_id
                                                        }
                                                        movement={movement}
                                                        movementIndex={
                                                            movementIndex
                                                        }
                                                        sourceMovement={
                                                            preview.movements[
                                                                movementIndex
                                                            ]
                                                        }
                                                        updateMovement={
                                                            updateMovement
                                                        }
                                                        movementError={
                                                            movementError
                                                        }
                                                    />
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

CreateStatementImport.layout = {
    breadcrumbs: [
        { title: 'Statement Imports', href: index() },
        { title: 'Import statement', href: '#' },
    ],
};
