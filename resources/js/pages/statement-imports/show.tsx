import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, FileCheck2, PencilLine } from 'lucide-react';
import { useState } from 'react';
import { update as updateStatementMovementClassification } from '@/actions/App/Http/Controllers/StatementMovementClassificationController';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    incomeSourceLabel,
    movementKindLabel,
    transferPurposeLabel,
} from '@/lib/money-movement';
import {
    statementMovementClassificationOptions,
    statementMovementContributesToSpending,
} from '@/lib/statement-movement-classification';
import { index as breakdownIndex } from '@/routes/breakdown';
import { index, show } from '@/routes/statement_imports';
import type {
    Currency,
    FinancialStatementFormat,
    MoneyMovementDetails,
    StatementClassification,
    StatementDirection,
} from '@/types';
import StatementMovementRow from './statement-movement-row';

type Summary = Record<
    Currency,
    {
        spending_minor: string;
        refunds_minor: string;
        income_minor: string;
        transfers_in_minor: string;
        transfers_out_minor: string;
        savings_deposits_minor: string;
        savings_withdrawals_minor: string;
        net_savings_minor: string;
    }
>;

type LinkedTransaction = {
    id: number;
    voided_at: string | null;
    category: { id: number; name: string } | null;
} & MoneyMovementDetails;

type StatementImportDetail = {
    id: number;
    financial_statement_format: FinancialStatementFormat;
    parser_version: string;
    period_start: string;
    period_end: string;
    instrument_label: string;
    instrument_last_four: string | null;
    movement_count: number;
    confirmed_at: string;
    reconciliation: Record<string, string>;
    excluded_values: Array<{
        position: number;
        occurred_on: string;
        amount_minor: string;
        currency: Currency;
        direction: StatementDirection;
        description: string;
        source_metadata: Record<string, unknown>;
    }>;
    summary: Summary;
    movements: Array<{
        id: number;
        position: number;
        occurred_on: string;
        amount_minor: string;
        currency: Currency;
        direction: StatementDirection;
        classification: StatementClassification;
        description: string;
        resolution: 'linked' | 'created';
        source_metadata: Record<string, unknown>;
        match_evidence: Record<string, unknown>;
        transaction: LinkedTransaction;
    }>;
};

const summaryRows = [
    { key: 'spending_minor', label: 'Spending' },
    { key: 'refunds_minor', label: 'Refunds' },
    { key: 'income_minor', label: 'Income' },
    { key: 'transfers_in_minor', label: 'Transfers in' },
    { key: 'transfers_out_minor', label: 'Transfers out' },
    { key: 'savings_deposits_minor', label: 'Savings deposits' },
    { key: 'savings_withdrawals_minor', label: 'Savings withdrawals' },
    { key: 'net_savings_minor', label: 'Net savings' },
] satisfies Array<{ key: keyof Summary['PEN']; label: string }>;
const supportedSummaryCurrencies = ['PEN', 'USD'] satisfies Currency[];

function reconciliationCurrency(key: string): Currency {
    return key.includes('_usd_') ? 'USD' : 'PEN';
}

function statementReconciliationCurrency(
    statementImport: StatementImportDetail,
    key: string,
): Currency {
    if (statementImport.financial_statement_format === 'bcp') {
        return (
            statementImport.movements.at(0)?.currency ??
            reconciliationCurrency(key)
        );
    }

    return reconciliationCurrency(key);
}

function statementSummaryCurrencies(
    statementImport: StatementImportDetail,
): Currency[] {
    if (statementImport.financial_statement_format === 'interbank') {
        return supportedSummaryCurrencies;
    }

    const movementCurrency = statementImport.movements.at(0)?.currency;

    if (movementCurrency !== undefined) {
        return [movementCurrency];
    }

    const reconciliationKey = Object.keys(statementImport.reconciliation).at(0);

    return reconciliationKey === undefined
        ? []
        : [reconciliationCurrency(reconciliationKey)];
}

function transactionTaxonomy(transaction: LinkedTransaction): string {
    switch (transaction.kind) {
        case 'spending':
        case 'refund':
            return transaction.category?.name ?? 'Uncategorized';
        case 'income':
            return incomeSourceLabel(transaction.income_source);
        case 'transfer':
            return transferPurposeLabel(transaction.transfer_purpose);
    }
}

function auditValue(value: unknown): string {
    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return 'retained';
}

function EditableStatementMovementRow({
    statementImportId,
    movement,
}: {
    statementImportId: number;
    movement: StatementImportDetail['movements'][number];
}) {
    const [open, setOpen] = useState(false);
    const classificationOptions = statementMovementClassificationOptions.filter(
        (option) =>
            option.value !== 'needs_classification' &&
            option.value !== 'not_a_movement',
    );

    const row = (
        <StatementMovementRow
            position={movement.position}
            occurredOn={movement.occurred_on}
            description={movement.description}
            amountMinor={movement.amount_minor}
            currency={movement.currency}
            direction={movement.direction}
            classification={movement.classification}
            dataTest={`confirmed-statement-movement-${movement.position}`}
            detail={
                <div className="grid gap-1 text-xs text-muted-foreground">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {movement.resolution === 'linked'
                                ? 'Linked to recorded Transaction'
                                : 'Added from statement'}
                        </Badge>
                        <Link
                            href={breakdownIndex({
                                query: {
                                    currency: movement.currency,
                                    preset: 'custom',
                                    date_from: movement.occurred_on,
                                    date_to: movement.occurred_on,
                                    selected: movement.transaction.id,
                                },
                            })}
                            className="inline-flex items-center gap-1 hover:underline"
                        >
                            {movement.transaction.voided_at ? 'Voided ' : ''}
                            {movementKindLabel(movement.transaction.kind)}
                            <ExternalLink className="size-3" />
                        </Link>
                        <span>{transactionTaxonomy(movement.transaction)}</span>
                    </div>
                    {Object.keys(movement.match_evidence).length > 0 && (
                        <span>
                            Match evidence:{' '}
                            {Object.entries(movement.match_evidence)
                                .map(
                                    ([key, value]) =>
                                        `${key.replaceAll('_', ' ')} ${auditValue(value)}`,
                                )
                                .join(' · ')}
                        </span>
                    )}
                    {Object.keys(movement.source_metadata).length > 0 && (
                        <span>
                            Source audit:{' '}
                            {Object.entries(movement.source_metadata)
                                .map(
                                    ([key, value]) =>
                                        `${key.replaceAll('_', ' ')} ${auditValue(value)}`,
                                )
                                .join(' · ')}
                        </span>
                    )}
                </div>
            }
            action={
                <DialogTrigger asChild>
                    <Button type="button" variant="outline" size="sm">
                        <PencilLine /> Edit
                    </Button>
                </DialogTrigger>
            }
        />
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {row}
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Edit Statement Movement classification
                    </DialogTitle>
                    <DialogDescription>
                        Use Transfer only for money moved between your own
                        accounts. Third-party money out is Spending, and money
                        returned by a third party is a Refund or reimbursement.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...updateStatementMovementClassification.form({
                        statement_import: statementImportId,
                        movement: movement.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <NativeSelect
                                name="classification"
                                aria-label={`Classification for ${movement.description}`}
                                defaultValue={movement.classification}
                                options={classificationOptions}
                                required
                            />
                            <InputError message={errors.classification} />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Save classification
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function StatementImportShow({
    statement_import,
}: {
    statement_import: StatementImportDetail;
}) {
    const spendingMovementCount = statement_import.movements.filter(
        (movement) =>
            statementMovementContributesToSpending(movement.classification),
    ).length;
    const outsideNetSpendingCount =
        statement_import.movement_count - spendingMovementCount;
    const summaryCurrencies = statementSummaryCurrencies(statement_import);

    return (
        <>
            <Head
                title={`${statement_import.instrument_label} Statement Import`}
            />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {statement_import.instrument_label}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Audit how every statement movement was linked,
                            added, or explicitly excluded.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={index()}>
                            <ArrowLeft /> Statement Imports
                        </Link>
                    </Button>
                </div>

                <div className="grid min-h-0 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-stretch">
                    <Card
                        className="min-w-0"
                        data-test="statement-import-overview"
                    >
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="grid gap-1">
                                    <CardTitle id="confirmed-import-heading">
                                        Verified statement period
                                    </CardTitle>
                                    <CardDescription>
                                        {statement_import.period_start} through{' '}
                                        {statement_import.period_end}
                                    </CardDescription>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">
                                        {statement_import.financial_statement_format.toUpperCase()}
                                    </Badge>
                                    <Badge variant="secondary">
                                        <FileCheck2 />
                                        Verified
                                    </Badge>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent className="grid gap-6">
                            <section
                                className="grid gap-3"
                                aria-label="Import totals"
                            >
                                <div className="grid grid-cols-3 divide-x rounded-lg bg-muted/50 py-4">
                                    <div className="grid gap-1 px-3">
                                        <span className="text-xs text-muted-foreground">
                                            Movements
                                        </span>
                                        <span className="text-xl font-semibold tabular-nums">
                                            {statement_import.movement_count}
                                        </span>
                                    </div>
                                    <div className="grid gap-1 px-3">
                                        <span className="text-xs text-muted-foreground">
                                            Affect Net Spending
                                        </span>
                                        <span className="text-xl font-semibold tabular-nums">
                                            {spendingMovementCount}
                                        </span>
                                    </div>
                                    <div className="grid gap-1 px-3">
                                        <span className="text-xs text-muted-foreground">
                                            Outside Net Spending
                                        </span>
                                        <span className="text-xl font-semibold tabular-nums">
                                            {outsideNetSpendingCount}
                                        </span>
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                                    <span className="tabular-nums">
                                        Last four{' '}
                                        {statement_import.instrument_last_four ??
                                            'not provided'}
                                    </span>
                                    <span>
                                        Confirmed{' '}
                                        {new Date(
                                            statement_import.confirmed_at,
                                        ).toLocaleString()}
                                    </span>
                                    <span>
                                        Parser {statement_import.parser_version}
                                    </span>
                                </div>
                            </section>

                            <section
                                className="grid gap-4 border-t pt-6"
                                aria-labelledby="statement-summary-heading"
                            >
                                <div className="grid gap-1">
                                    <h2
                                        id="statement-summary-heading"
                                        className="font-semibold"
                                    >
                                        Statement summary
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Classification totals retained for this
                                        statement.
                                    </p>
                                </div>
                                <div
                                    className="overflow-hidden rounded-lg border"
                                    data-test="statement-summary"
                                >
                                    <table className="w-full table-fixed text-sm">
                                        <thead className="bg-muted/50">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="w-2/5 px-3 py-2.5 text-left text-xs font-medium text-muted-foreground"
                                                >
                                                    Classification
                                                </th>
                                                {summaryCurrencies.map(
                                                    (currency) => (
                                                        <th
                                                            key={currency}
                                                            scope="col"
                                                            className="px-3 py-2.5 text-right text-xs font-semibold"
                                                        >
                                                            {currency}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {summaryRows.map(
                                                ({ key, label }) => (
                                                    <tr
                                                        key={key}
                                                        className={
                                                            key ===
                                                            'net_savings_minor'
                                                                ? 'bg-muted/30'
                                                                : undefined
                                                        }
                                                    >
                                                        <th
                                                            scope="row"
                                                            className="px-3 py-2.5 text-left text-xs font-normal text-muted-foreground sm:text-sm"
                                                        >
                                                            {label}
                                                        </th>
                                                        {summaryCurrencies.map(
                                                            (currency) => (
                                                                <td
                                                                    key={
                                                                        currency
                                                                    }
                                                                    className="px-3 py-2.5 text-right text-xs font-medium tabular-nums sm:text-sm"
                                                                >
                                                                    {formatMinorUnits(
                                                                        statement_import
                                                                            .summary[
                                                                            currency
                                                                        ][key],
                                                                        currency,
                                                                    )}
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section
                                className="grid gap-4 border-t pt-6"
                                aria-labelledby="source-reconciliation-heading"
                            >
                                <div className="grid gap-1">
                                    <h2
                                        id="source-reconciliation-heading"
                                        className="font-semibold"
                                    >
                                        Source reconciliation
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Printed totals retained from the
                                        confirmed statement.
                                    </p>
                                </div>
                                <dl className="grid gap-x-6 sm:grid-cols-2">
                                    {Object.entries(
                                        statement_import.reconciliation,
                                    ).map(([key, value]) => (
                                        <div
                                            key={key}
                                            className="flex items-center justify-between gap-3 border-b py-2.5"
                                        >
                                            <dt className="text-xs text-muted-foreground capitalize">
                                                {key
                                                    .replaceAll('_minor', '')
                                                    .replaceAll('_', ' ')}
                                            </dt>
                                            <dd className="text-sm font-medium tabular-nums">
                                                {formatMinorUnits(
                                                    value,
                                                    statementReconciliationCurrency(
                                                        statement_import,
                                                        key,
                                                    ),
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                {statement_import.excluded_values.length >
                                    0 && (
                                    <div className="grid gap-3 border-t pt-4">
                                        <div className="grid gap-1">
                                            <h3 className="text-sm font-medium">
                                                Excluded source values
                                            </h3>
                                            <p className="text-xs text-muted-foreground">
                                                These parser candidates were
                                                confirmed as informational, so
                                                they did not create
                                                Transactions.
                                            </p>
                                        </div>
                                        <div className="grid gap-2">
                                            {statement_import.excluded_values.map(
                                                (value) => (
                                                    <div
                                                        key={`${value.position}-${value.description}`}
                                                        className="flex items-start justify-between gap-3 rounded-lg border p-3 text-sm"
                                                    >
                                                        <div className="grid gap-0.5">
                                                            <span className="font-medium">
                                                                {
                                                                    value.description
                                                                }
                                                            </span>
                                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                                {
                                                                    value.occurred_on
                                                                }
                                                            </span>
                                                            {Object.keys(
                                                                value.source_metadata,
                                                            ).length > 0 && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    Source
                                                                    audit:{' '}
                                                                    {Object.entries(
                                                                        value.source_metadata,
                                                                    )
                                                                        .map(
                                                                            ([
                                                                                key,
                                                                                sourceValue,
                                                                            ]) =>
                                                                                `${key.replaceAll('_', ' ')} ${auditValue(sourceValue)}`,
                                                                        )
                                                                        .join(
                                                                            ' · ',
                                                                        )}
                                                                </span>
                                                            )}
                                                        </div>
                                                        <span className="font-medium tabular-nums">
                                                            {formatMinorUnits(
                                                                value.amount_minor,
                                                                value.currency,
                                                            )}
                                                        </span>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                            </section>
                        </CardContent>
                    </Card>

                    <div
                        className="min-w-0 lg:relative lg:min-h-0"
                        data-test="statement-movements-column"
                    >
                        <Card className="min-w-0 lg:absolute lg:inset-0 lg:min-h-0">
                            <CardHeader className="shrink-0">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="grid gap-1">
                                        <CardTitle>
                                            Statement Movements
                                        </CardTitle>
                                        <CardDescription>
                                            Review each posted movement and
                                            correct its categorization when
                                            needed.
                                        </CardDescription>
                                    </div>
                                    <Badge variant="secondary">
                                        {statement_import.movement_count}{' '}
                                        {statement_import.movement_count === 1
                                            ? 'movement'
                                            : 'movements'}
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
                                        {statement_import.movements.map(
                                            (movement) => (
                                                <EditableStatementMovementRow
                                                    key={movement.id}
                                                    statementImportId={
                                                        statement_import.id
                                                    }
                                                    movement={movement}
                                                />
                                            ),
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

StatementImportShow.layout = ({
    statement_import,
}: {
    statement_import: StatementImportDetail;
}) => ({
    breadcrumbs: [
        { title: 'Statement Imports', href: index() },
        {
            title: statement_import.instrument_label,
            href: show(statement_import.id),
        },
    ],
});
