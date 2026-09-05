import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowLeft,
    ArrowUpRight,
    Ban,
    CheckCircle2,
    CircleAlert,
    CircleDollarSign,
    CircleOff,
    FileCheck2,
    Link2,
    PencilLine,
    Plus,
    Upload,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    statementMovementClassificationOptions,
    statementMovementContributesToSpending,
} from '@/lib/statement-movement-classification';
import { index as statementImportsIndex } from '@/routes/statement_imports';
import type {
    StatementConfirmationMovement,
    StatementClassification,
    StatementImportPreview,
    StatementPreviewMovement,
} from '@/types';

type ConfirmationMovement = StatementConfirmationMovement;

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
        movement: ConfirmationMovement,
    ) => void;
    movementError: (
        movementIndex: number,
        field: keyof ConfirmationMovement,
    ) => string | undefined;
};

type MovementStatus =
    | 'needs_classification'
    | 'needs_transaction'
    | 'linked'
    | 'created'
    | 'excluded'
    | 'affects_spending'
    | 'outside_spending';

const movementStatusDetails = {
    needs_classification: {
        label: 'Needs classification',
        detail: 'Choose what kind of movement this is before confirming.',
        icon: CircleAlert,
        className:
            'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
    },
    needs_transaction: {
        label: 'Needs Transaction association',
        detail: 'Choose a recorded Transaction or add this movement as a new one.',
        icon: Link2,
        className:
            'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
    },
    linked: {
        label: 'Linked to recorded Transaction',
        detail: 'This movement will use an existing recorded Transaction.',
        icon: Link2,
        className:
            'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-300',
    },
    created: {
        label: 'Will be added',
        detail: 'A new Transaction will be added for this movement.',
        icon: Plus,
        className:
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300',
    },
    excluded: {
        label: 'Excluded from Transactions',
        detail: 'This informational value will not create a Transaction.',
        icon: Ban,
        className:
            'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-300',
    },
    affects_spending: {
        label: 'Affects Net Spending',
        detail: 'This movement affects Net Spending.',
        icon: CircleDollarSign,
        className:
            'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-300',
    },
    outside_spending: {
        label: 'Outside Net Spending',
        detail: 'This movement does not affect Net Spending.',
        icon: CircleOff,
        className:
            'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300',
    },
} satisfies Record<
    MovementStatus,
    {
        label: string;
        detail: string;
        icon: LucideIcon;
        className: string;
    }
>;

function MovementStatusBadge({ status }: { status: MovementStatus }) {
    const details = movementStatusDetails[status];
    const StatusIcon = details.icon;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge
                    variant="outline"
                    className={`size-5 justify-center rounded-full p-0 shadow-none [&>svg]:size-2.5 ${details.className}`}
                    aria-label={details.label}
                    data-status={status}
                    tabIndex={0}
                >
                    <StatusIcon />
                </Badge>
            </TooltipTrigger>
            <TooltipContent>{details.detail}</TooltipContent>
        </Tooltip>
    );
}

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
    const needsClassification =
        movement.classification === 'needs_classification';
    const needsMatchDecision = movement.resolution === 'needs_resolution';
    const isUnresolved = needsClassification || needsMatchDecision;
    const affectsNetSpending = statementMovementContributesToSpending(
        movement.classification,
    );
    const DirectionIcon = isMoneyIn ? ArrowDownLeft : ArrowUpRight;
    const classificationError = movementError(movementIndex, 'classification');
    const resolutionError = movementError(movementIndex, 'resolution');
    const compatibleCandidates = sourceMovement.match.candidates.filter(
        (candidate) =>
            candidate.compatible_classifications.includes(
                movement.classification,
            ),
    );
    const matchSelection =
        movement.resolution === 'link' && movement.transaction_id !== null
            ? `link:${movement.transaction_id}`
            : movement.resolution === 'create'
              ? 'create'
              : '';
    const resolutionStatus: MovementStatus = needsMatchDecision
        ? 'needs_transaction'
        : movement.resolution === 'link'
          ? 'linked'
          : movement.resolution === 'exclude'
            ? 'excluded'
            : 'created';
    const movementStatuses: MovementStatus[] = [
        ...(needsClassification
            ? (['needs_classification'] satisfies MovementStatus[])
            : []),
        resolutionStatus,
        ...(!needsClassification
            ? ([
                  affectsNetSpending ? 'affects_spending' : 'outside_spending',
              ] satisfies MovementStatus[])
            : []),
    ];

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
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span
                                className="tabular-nums"
                                data-test={`statement-movement-date-${movementIndex}`}
                            >
                                {movement.occurred_on}
                            </span>
                            <div
                                className="flex items-center gap-1"
                                data-test={`statement-movement-status-${movementIndex}`}
                            >
                                {movementStatuses.map((status) => (
                                    <MovementStatusBadge
                                        key={status}
                                        status={status}
                                    />
                                ))}
                            </div>
                        </div>
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
                                    ...movement,
                                    classification,
                                    ...(classification === 'not_a_movement'
                                        ? {
                                              resolution: 'exclude',
                                              transaction_id: null,
                                          }
                                        : sourceMovement.match.status ===
                                            'matched'
                                          ? {
                                                resolution: 'link',
                                                transaction_id:
                                                    sourceMovement.match
                                                        .transaction_id,
                                            }
                                          : sourceMovement.match.status ===
                                              'ambiguous'
                                            ? {
                                                  resolution:
                                                      'needs_resolution',
                                                  transaction_id: null,
                                              }
                                            : {
                                                  resolution: 'create',
                                                  transaction_id: null,
                                              }),
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
                <TableCell className="min-w-64 whitespace-normal">
                    {sourceMovement.match.status === 'ambiguous' &&
                    !needsClassification ? (
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`movement-${movementIndex}-resolution`}
                                className="sr-only"
                            >
                                Match decision for {movement.description}
                            </Label>
                            <NativeSelect
                                id={`movement-${movementIndex}-resolution`}
                                aria-label={`Match decision for ${movement.description}`}
                                value={matchSelection}
                                onChange={(event) => {
                                    const selection = event.currentTarget.value;

                                    if (selection === 'create') {
                                        updateMovement(movementIndex, {
                                            ...movement,
                                            resolution: 'create',
                                            transaction_id: null,
                                        });

                                        return;
                                    }

                                    if (selection.startsWith('link:')) {
                                        updateMovement(movementIndex, {
                                            ...movement,
                                            resolution: 'link',
                                            transaction_id: Number(
                                                selection.slice(5),
                                            ),
                                        });
                                    }
                                }}
                                options={[
                                    {
                                        value: '',
                                        label: 'Choose a match',
                                    },
                                    ...compatibleCandidates.map(
                                        (candidate) => ({
                                            value: `link:${candidate.id}`,
                                            label: `${candidate.occurred_on} · ${candidate.description}`,
                                        }),
                                    ),
                                    {
                                        value: 'create',
                                        label: 'Add as new Transaction',
                                    },
                                ]}
                                aria-invalid={Boolean(resolutionError)}
                                aria-describedby={
                                    resolutionError
                                        ? `movement-${movementIndex}-resolution-error`
                                        : undefined
                                }
                            />
                            <InputError
                                id={`movement-${movementIndex}-resolution-error`}
                                message={resolutionError}
                            />
                        </div>
                    ) : (
                        <span className="text-muted-foreground" aria-hidden>
                            —
                        </span>
                    )}
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
                                    ...movement,
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
                                    ...movement,
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
                                    ...movement,
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
                                        ...movement,
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
                movement.classification === 'needs_classification' ||
                movement.resolution === 'needs_resolution',
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
        updatedMovement: ConfirmationMovement,
    ): void {
        confirmation.clearErrors();
        confirmation.setData(
            'movements',
            confirmation.data.movements.map((movement, currentIndex) =>
                currentIndex === movementIndex ? updatedMovement : movement,
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
                            then review every parsed movement. Rows that need a
                            decision are marked for your attention.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={statementImportsIndex()}>
                            <ArrowLeft /> Statement Imports
                        </Link>
                    </Button>
                </div>

                <div className="grid min-h-0 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-stretch">
                    <div
                        className="min-w-0"
                        data-test="statement-import-overview"
                    >
                        <Card className="min-w-0">
                            <CardHeader>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="grid gap-1">
                                        <CardTitle>Statement details</CardTitle>
                                        <CardDescription>
                                            The PDF and extracted text stay in
                                            this tab until you confirm or leave.
                                        </CardDescription>
                                    </div>
                                    {preview && (
                                        <div className="flex flex-wrap gap-2">
                                            <Badge variant="outline">
                                                {preview.financial_statement_format.toUpperCase()}
                                            </Badge>
                                            <Badge variant="secondary">
                                                <FileCheck2 /> Reconciled
                                            </Badge>
                                        </div>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent className="grid gap-6">
                                <section className="grid gap-3">
                                    <div className="grid gap-1">
                                        <h2 className="font-semibold">
                                            1. Upload
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            Refreshing or leaving this page
                                            discards the preview.
                                        </p>
                                    </div>
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
                                                    setSelectedStatement(
                                                        statement,
                                                    );
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
                                                    previewRequest.errors
                                                        .statement
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
                                            Upload and check
                                        </Button>
                                    </form>
                                </section>

                                {preview && (
                                    <>
                                        <Separator />
                                        <section
                                            className="grid gap-4"
                                            data-test="statement-checks"
                                        >
                                            <div className="grid gap-1">
                                                <h2 className="font-semibold">
                                                    Reconciliation
                                                </h2>
                                                <p className="text-sm text-muted-foreground">
                                                    Printed statement totals
                                                    verified against every
                                                    parsed Statement Movement.
                                                </p>
                                            </div>
                                            <dl className="grid gap-x-6 sm:grid-cols-2">
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
                                                            className="flex items-center justify-between gap-3 border-b py-2.5"
                                                        >
                                                            <dt className="text-xs text-muted-foreground capitalize">
                                                                {humanizeKey(
                                                                    key,
                                                                )}
                                                            </dt>
                                                            <dd className="text-sm font-medium tabular-nums">
                                                                {formatMinorUnits(
                                                                    value,
                                                                    currency,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    );
                                                })}
                                            </dl>
                                        </section>

                                        <form
                                            onSubmit={confirm}
                                            className="grid gap-5 border-t pt-6"
                                        >
                                            <div className="grid gap-1">
                                                <h2 className="font-semibold">
                                                    3. Confirm
                                                </h2>
                                                <p className="text-sm text-muted-foreground">
                                                    {preview.period_start}{' '}
                                                    through {preview.period_end}
                                                </p>
                                            </div>
                                            <dl
                                                className="grid grid-cols-4 divide-x rounded-lg bg-muted/50 py-4"
                                                aria-label="Import totals"
                                                data-test="statement-import-totals"
                                            >
                                                <div className="grid gap-1 px-3">
                                                    <dt className="text-xs text-muted-foreground">
                                                        Proposed movements
                                                    </dt>
                                                    <dd className="text-xl font-semibold tabular-nums">
                                                        {
                                                            confirmation.data
                                                                .movements
                                                                .length
                                                        }
                                                    </dd>
                                                </div>
                                                <div className="grid gap-1 px-3">
                                                    <dt className="text-xs text-muted-foreground">
                                                        Affect Net Spending
                                                    </dt>
                                                    <dd className="text-xl font-semibold tabular-nums">
                                                        {spendingMovementCount}
                                                    </dd>
                                                </div>
                                                <div className="grid gap-1 px-3">
                                                    <dt className="text-xs text-muted-foreground">
                                                        Outside Net Spending
                                                    </dt>
                                                    <dd className="text-xl font-semibold tabular-nums">
                                                        {
                                                            outsideNetSpendingCount
                                                        }
                                                    </dd>
                                                </div>
                                                <div className="grid gap-1 px-3">
                                                    <dt className="text-xs text-muted-foreground">
                                                        Unresolved
                                                    </dt>
                                                    <dd className="text-xl font-semibold tabular-nums">
                                                        {unresolvedCount}
                                                    </dd>
                                                </div>
                                            </dl>

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
                                            <div className="flex flex-col items-stretch justify-between gap-4 border-t pt-5 sm:flex-row sm:items-center">
                                                <div className="grid gap-1">
                                                    <p className="font-medium">
                                                        {unresolvedCount === 0
                                                            ? 'Ready for confirmation'
                                                            : `${unresolvedCount} movement${unresolvedCount === 1 ? '' : 's'} still need a decision`}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        The PDF remains in this
                                                        tab's memory.
                                                        Confirmation reparses it
                                                        and checks every
                                                        reconciliation
                                                        invariant.
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
                                                    Confirm import
                                                </Button>
                                            </div>
                                        </form>
                                    </>
                                )}
                            </CardContent>
                        </Card>
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
                                            <CardTitle>
                                                2. Review movements
                                            </CardTitle>
                                            <CardDescription>
                                                Review the full statement. Rows
                                                that need a classification or
                                                match decision are marked.
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
                                    <Table className="min-w-[64rem]">
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
                                                <TableHead>
                                                    Recorded Transaction
                                                </TableHead>
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
        { title: 'Statement Imports', href: statementImportsIndex() },
        { title: 'Import statement', href: '#' },
    ],
};
