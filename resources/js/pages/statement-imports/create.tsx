import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { ArrowLeft, FileCheck2, Upload } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    statementMovementClassificationOptions,
    statementMovementContributesToSpending,
} from '@/lib/statement-movement-classification';
import { index } from '@/routes/statement_imports';
import type {
    CategoryOption,
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
    warda_category_id: string;
    movements: ConfirmationMovement[];
};

function humanizeKey(key: string) {
    return key.replaceAll('_minor', '').replaceAll('_', ' ');
}

function reconciliationCurrency(key: string): 'PEN' | 'USD' {
    return key.includes('_usd_') ? 'USD' : 'PEN';
}

export default function CreateStatementImport({
    category_options,
    suggested_warda_category_id,
}: {
    category_options: CategoryOption[];
    suggested_warda_category_id: number | null;
}) {
    const previewRequest = useHttp<
        { statement: File | null },
        StatementImportPreview
    >({ statement: null });
    const confirmation = useForm<ConfirmationData>({
        statement: null,
        file_hash: '',
        instrument_label: '',
        instrument_last_four: '',
        warda_category_id: suggested_warda_category_id?.toString() ?? '',
        movements: [],
    });
    const [preview, setPreview] = useState<StatementImportPreview | null>(null);
    const hasWarda = confirmation.data.movements.some(
        (movement) => movement.classification === 'warda',
    );

    function requestPreview(event: React.FormEvent) {
        event.preventDefault();
        setPreview(null);
        confirmation.reset();
        confirmation.clearErrors();
        const form = event.currentTarget as HTMLFormElement;
        const statementInput = form.elements.namedItem(
            'statement',
        ) as HTMLInputElement | null;
        previewRequest.transform((data) => ({
            ...data,
            statement: statementInput?.files?.[0] ?? data.statement,
        }));
        previewRequest.post(previewStatementImport.url(), {
            onSuccess: (response) => {
                setPreview(response);
                confirmation.setData({
                    statement: null,
                    file_hash: response.file_hash,
                    instrument_label: response.instrument_label,
                    instrument_last_four: response.instrument_last_four ?? '',
                    warda_category_id:
                        suggested_warda_category_id?.toString() ?? '',
                    movements: response.movements.map((movement) => ({
                        source_row_id: movement.source_row_id,
                        occurred_on: movement.occurred_on,
                        description: movement.description,
                        amount_minor: movement.amount_minor,
                        currency: movement.currency,
                        classification: movement.classification,
                    })),
                });
            },
        });
    }

    function updateMovement(
        index: number,
        values: Partial<ConfirmationMovement>,
    ) {
        confirmation.clearErrors();
        confirmation.setData(
            'movements',
            confirmation.data.movements.map((movement, movementIndex) =>
                movementIndex === index ? { ...movement, ...values } : movement,
            ),
        );
    }

    function movementError(
        movementIndex: number,
        field: keyof ConfirmationMovement,
    ) {
        return (confirmation.errors as Record<string, string | undefined>)[
            `movements.${movementIndex}.${field}`
        ];
    }

    function confirm(event: React.FormEvent) {
        event.preventDefault();
        const form = event.currentTarget as HTMLFormElement;
        const statementInput = form.elements.namedItem(
            'statement',
        ) as HTMLInputElement | null;
        confirmation.transform((data) => ({
            ...data,
            statement: statementInput?.files?.[0] ?? data.statement,
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
                            Preview a supported BCP or Interbank text PDF, then
                            classify every movement before confirming it.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={index()}>
                            <ArrowLeft /> Statement Imports
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>1. Preview the PDF</CardTitle>
                        <CardDescription>
                            The source and extracted text are processed
                            transiently and are not retained.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={requestPreview}
                            className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
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
                                        setPreview(null);
                                        previewRequest.clearErrors('statement');
                                        previewRequest.setData(
                                            'statement',
                                            event.target.files?.[0] ?? null,
                                        );
                                    }}
                                    required
                                />
                                <InputError
                                    message={previewRequest.errors.statement}
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={
                                    previewRequest.processing ||
                                    previewRequest.data.statement === null
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
                    <form onSubmit={confirm} className="grid gap-6">
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="grid gap-1">
                                        <CardTitle>
                                            2. Review every movement
                                        </CardTitle>
                                        <CardDescription>
                                            {preview.period_start} through{' '}
                                            {preview.period_end}
                                        </CardDescription>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {preview.financial_statement_format.toUpperCase()}
                                        </Badge>
                                        <Badge variant="secondary">
                                            <FileCheck2 /> Reconciled
                                        </Badge>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="grid gap-5">
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
                                                    event.target.value,
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
                                                    event.target.value,
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
                                    {hasWarda && (
                                        <div className="grid gap-2 md:col-span-3">
                                            <Label htmlFor="warda-category">
                                                Savings Category for WARDA
                                            </Label>
                                            <NativeSelect
                                                id="warda-category"
                                                value={
                                                    confirmation.data
                                                        .warda_category_id
                                                }
                                                onChange={(event) => {
                                                    confirmation.clearErrors(
                                                        'warda_category_id',
                                                    );
                                                    confirmation.setData(
                                                        'warda_category_id',
                                                        event.target.value,
                                                    );
                                                }}
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'Select an active Category',
                                                    },
                                                    ...category_options.map(
                                                        (category) => ({
                                                            value: category.id.toString(),
                                                            label: category.path,
                                                        }),
                                                    ),
                                                ]}
                                                required
                                            />
                                            <InputError
                                                message={
                                                    confirmation.errors
                                                        .warda_category_id
                                                }
                                            />
                                        </div>
                                    )}
                                </div>

                                <div className="rounded-lg border md:overflow-x-auto">
                                    <table className="block w-full text-sm md:table md:min-w-[70rem]">
                                        <thead className="hidden bg-muted/50 text-left md:table-header-group">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">
                                                    Date
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Description
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Amount
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Currency
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Direction
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Classification
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Spending impact
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="grid gap-3 p-3 md:table-row-group md:p-0">
                                            {confirmation.data.movements.map(
                                                (movement, movementIndex) => (
                                                    <tr
                                                        key={
                                                            movement.source_row_id
                                                        }
                                                        className="grid gap-3 rounded-lg border p-3 align-top md:table-row md:rounded-none md:border-0 md:p-0"
                                                    >
                                                        <td className="grid gap-2 p-0 md:table-cell md:p-2">
                                                            <Label
                                                                htmlFor={`movement-${movementIndex}-occurred-on`}
                                                                className="text-xs text-muted-foreground md:sr-only"
                                                            >
                                                                Date
                                                            </Label>
                                                            <Input
                                                                id={`movement-${movementIndex}-occurred-on`}
                                                                type="date"
                                                                value={
                                                                    movement.occurred_on
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateMovement(
                                                                        movementIndex,
                                                                        {
                                                                            occurred_on:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                aria-invalid={Boolean(
                                                                    movementError(
                                                                        movementIndex,
                                                                        'occurred_on',
                                                                    ),
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
                                                        </td>
                                                        <td className="grid gap-2 p-0 md:table-cell md:p-2">
                                                            <Label
                                                                htmlFor={`movement-${movementIndex}-description`}
                                                                className="text-xs text-muted-foreground md:sr-only"
                                                            >
                                                                Description
                                                            </Label>
                                                            <Input
                                                                id={`movement-${movementIndex}-description`}
                                                                value={
                                                                    movement.description
                                                                }
                                                                maxLength={255}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateMovement(
                                                                        movementIndex,
                                                                        {
                                                                            description:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                aria-invalid={Boolean(
                                                                    movementError(
                                                                        movementIndex,
                                                                        'description',
                                                                    ),
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
                                                        </td>
                                                        <td className="grid gap-2 p-0 md:table-cell md:p-2">
                                                            <Label
                                                                htmlFor={`movement-${movementIndex}-amount`}
                                                                className="text-xs text-muted-foreground md:sr-only"
                                                            >
                                                                Amount in minor
                                                                units
                                                            </Label>
                                                            <Input
                                                                id={`movement-${movementIndex}-amount`}
                                                                value={
                                                                    movement.amount_minor
                                                                }
                                                                inputMode="numeric"
                                                                pattern="\d+"
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateMovement(
                                                                        movementIndex,
                                                                        {
                                                                            amount_minor:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                aria-invalid={Boolean(
                                                                    movementError(
                                                                        movementIndex,
                                                                        'amount_minor',
                                                                    ),
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
                                                                    movement.amount_minor ||
                                                                        '0',
                                                                    movement.currency,
                                                                )}
                                                            </p>
                                                        </td>
                                                        <td className="grid gap-2 p-0 md:table-cell md:p-2">
                                                            <Label
                                                                htmlFor={`movement-${movementIndex}-currency`}
                                                                className="text-xs text-muted-foreground md:sr-only"
                                                            >
                                                                Currency
                                                            </Label>
                                                            <NativeSelect
                                                                id={`movement-${movementIndex}-currency`}
                                                                value={
                                                                    movement.currency
                                                                }
                                                                aria-invalid={Boolean(
                                                                    movementError(
                                                                        movementIndex,
                                                                        'currency',
                                                                    ),
                                                                )}
                                                                aria-describedby={`movement-${movementIndex}-currency-error`}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateMovement(
                                                                        movementIndex,
                                                                        {
                                                                            currency:
                                                                                event
                                                                                    .target
                                                                                    .value as StatementPreviewMovement['currency'],
                                                                        },
                                                                    )
                                                                }
                                                                options={[
                                                                    {
                                                                        value: 'PEN',
                                                                        label: 'PEN',
                                                                    },
                                                                    {
                                                                        value: 'USD',
                                                                        label: 'USD',
                                                                    },
                                                                ]}
                                                            />
                                                            <InputError
                                                                id={`movement-${movementIndex}-currency-error`}
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'currency',
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="grid gap-1 p-0 capitalize md:table-cell md:px-3 md:py-3">
                                                            <span className="text-xs text-muted-foreground md:sr-only">
                                                                Direction
                                                            </span>
                                                            {
                                                                preview
                                                                    .movements[
                                                                    movementIndex
                                                                ].direction
                                                            }
                                                        </td>
                                                        <td className="grid gap-2 p-0 md:table-cell md:p-2">
                                                            <Label
                                                                htmlFor={`movement-${movementIndex}-classification`}
                                                                className="text-xs text-muted-foreground md:sr-only"
                                                            >
                                                                Classification
                                                            </Label>
                                                            <NativeSelect
                                                                id={`movement-${movementIndex}-classification`}
                                                                aria-label={`Classification for ${movement.description}`}
                                                                value={
                                                                    movement.classification
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateMovement(
                                                                        movementIndex,
                                                                        {
                                                                            classification:
                                                                                event
                                                                                    .target
                                                                                    .value as StatementClassification,
                                                                        },
                                                                    )
                                                                }
                                                                options={statementMovementClassificationOptions.filter(
                                                                    (option) =>
                                                                        option.value !==
                                                                            'not_a_movement' ||
                                                                        preview
                                                                            .movements[
                                                                            movementIndex
                                                                        ]
                                                                            .can_be_excluded,
                                                                )}
                                                                aria-invalid={Boolean(
                                                                    movementError(
                                                                        movementIndex,
                                                                        'classification',
                                                                    ),
                                                                )}
                                                                aria-describedby={`movement-${movementIndex}-classification-error`}
                                                            />
                                                            <InputError
                                                                id={`movement-${movementIndex}-classification-error`}
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'classification',
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="grid gap-2 p-0 md:table-cell md:px-3 md:py-3">
                                                            <span className="text-xs text-muted-foreground md:sr-only">
                                                                Spending impact
                                                            </span>
                                                            <Badge
                                                                variant={
                                                                    statementMovementContributesToSpending(
                                                                        movement.classification,
                                                                    )
                                                                        ? 'default'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                {statementMovementContributesToSpending(
                                                                    movement.classification,
                                                                )
                                                                    ? 'Affects spending'
                                                                    : 'Statement only'}
                                                            </Badge>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Reconciliation</CardTitle>
                                <CardDescription>
                                    Printed statement totals verified against
                                    every parsed row.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                {Object.entries(preview.reconciliation).map(
                                    ([key, value]) => {
                                        const currency =
                                            reconciliationCurrency(key);

                                        return (
                                            <div
                                                key={key}
                                                className="grid gap-1 rounded-lg border p-3"
                                            >
                                                <span className="text-xs text-muted-foreground capitalize">
                                                    {humanizeKey(key)}
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {formatMinorUnits(
                                                        value,
                                                        currency,
                                                    )}
                                                </span>
                                            </div>
                                        );
                                    },
                                )}
                            </CardContent>
                        </Card>

                        {preview.informational_values.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Informational values</CardTitle>
                                    <CardDescription>
                                        These values support review but are not
                                        posted movements.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    {preview.informational_values.map(
                                        (value, valueIndex) => (
                                            <div
                                                key={`${value.label}-${valueIndex}`}
                                                className="grid gap-1 rounded-lg border p-3"
                                            >
                                                <span className="text-xs text-muted-foreground">
                                                    {value.label}
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
                                </CardContent>
                            </Card>
                        )}

                        {Object.keys(confirmation.errors).length > 0 && (
                            <AlertError
                                title="The Statement Import was not confirmed."
                                errors={Object.values(confirmation.errors)}
                            />
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle>3. Confirm the same PDF</CardTitle>
                                <CardDescription>
                                    Re-upload the exact previewed file so the
                                    server can reparse it and verify every
                                    source row.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="grid min-w-0 flex-1 gap-2">
                                    <Label htmlFor="confirm-statement">
                                        Same statement PDF
                                    </Label>
                                    <Input
                                        id="confirm-statement"
                                        name="statement"
                                        type="file"
                                        accept="application/pdf,.pdf"
                                        onChange={(event) => {
                                            confirmation.clearErrors(
                                                'statement',
                                            );
                                            confirmation.setData(
                                                'statement',
                                                event.target.files?.[0] ?? null,
                                            );
                                        }}
                                        required
                                    />
                                    <InputError
                                        message={confirmation.errors.statement}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        confirmation.processing ||
                                        confirmation.data.statement === null
                                    }
                                >
                                    {confirmation.processing && <Spinner />}
                                    Confirm Statement Import
                                </Button>
                            </CardContent>
                        </Card>
                    </form>
                )}
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
