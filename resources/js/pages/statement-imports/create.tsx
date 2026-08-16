import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { ArrowLeft, FileCheck2, Upload } from 'lucide-react';
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

const classificationOptions: Array<{
    value: StatementClassification;
    label: string;
}> = [
    { value: 'needs_classification', label: 'Needs classification' },
    { value: 'purchase', label: 'Purchase' },
    { value: 'refund', label: 'Refund' },
    { value: 'fee', label: 'Bank fee' },
    { value: 'tax', label: 'Tax' },
    { value: 'income', label: 'Income' },
    { value: 'transfer', label: 'Transfer or payment' },
    { value: 'card_payment', label: 'Card payment' },
    { value: 'warda', label: 'WARDA' },
    { value: 'already_recorded', label: 'Already recorded' },
    { value: 'not_a_movement', label: 'Not a movement' },
];

const spendingClassifications = new Set<StatementClassification>([
    'purchase',
    'refund',
    'fee',
    'tax',
    'warda',
]);

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
    const preview = previewRequest.response;
    const hasWarda = confirmation.data.movements.some(
        (movement) => movement.classification === 'warda',
    );

    function requestPreview(event: React.FormEvent) {
        event.preventDefault();
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
                                    onChange={(event) =>
                                        previewRequest.setData(
                                            'statement',
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
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
                                            {preview.provider.toUpperCase()}
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
                                            onChange={(event) =>
                                                confirmation.setData(
                                                    'instrument_label',
                                                    event.target.value,
                                                )
                                            }
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
                                            onChange={(event) =>
                                                confirmation.setData(
                                                    'instrument_last_four',
                                                    event.target.value,
                                                )
                                            }
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
                                                onChange={(event) =>
                                                    confirmation.setData(
                                                        'warda_category_id',
                                                        event.target.value,
                                                    )
                                                }
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

                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full min-w-[70rem] text-sm">
                                        <thead className="bg-muted/50 text-left">
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
                                        <tbody className="divide-y">
                                            {confirmation.data.movements.map(
                                                (movement, movementIndex) => (
                                                    <tr
                                                        key={
                                                            movement.source_row_id
                                                        }
                                                        className="align-top"
                                                    >
                                                        <td className="p-2">
                                                            <Input
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
                                                            />
                                                            <InputError
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'occurred_on',
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="p-2">
                                                            <Input
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
                                                            />
                                                            <InputError
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'description',
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="p-2">
                                                            <Input
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
                                                            />
                                                            <InputError
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'amount_minor',
                                                                )}
                                                            />
                                                            <p className="pt-1 text-xs text-muted-foreground">
                                                                {formatMinorUnits(
                                                                    movement.amount_minor ||
                                                                        '0',
                                                                    movement.currency,
                                                                )}
                                                            </p>
                                                        </td>
                                                        <td className="p-2">
                                                            <NativeSelect
                                                                aria-label={`Currency for ${movement.description}`}
                                                                value={
                                                                    movement.currency
                                                                }
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
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'currency',
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="px-3 py-3 capitalize">
                                                            {
                                                                preview
                                                                    .movements[
                                                                    movementIndex
                                                                ].direction
                                                            }
                                                        </td>
                                                        <td className="p-2">
                                                            <NativeSelect
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
                                                                options={classificationOptions.filter(
                                                                    (option) =>
                                                                        option.value !==
                                                                            'not_a_movement' ||
                                                                        preview
                                                                            .movements[
                                                                            movementIndex
                                                                        ]
                                                                            .can_be_excluded,
                                                                )}
                                                            />
                                                            <InputError
                                                                message={movementError(
                                                                    movementIndex,
                                                                    'classification',
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="px-3 py-3">
                                                            <Badge
                                                                variant={
                                                                    spendingClassifications.has(
                                                                        movement.classification,
                                                                    )
                                                                        ? 'default'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                {spendingClassifications.has(
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
                                        onChange={(event) =>
                                            confirmation.setData(
                                                'statement',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
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
