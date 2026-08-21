import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { index, show } from '@/routes/statement_imports';
import { index as transactionsIndex } from '@/routes/transactions';
import type {
    Currency,
    StatementClassification,
    StatementDirection,
    StatementProvider,
} from '@/types';

type Summary = Record<
    Currency,
    {
        spending_minor: string;
        refunds_minor: string;
        income_minor: string;
        transfers_in_minor: string;
        transfers_out_minor: string;
        warda_deposits_minor: string;
        warda_withdrawals_minor: string;
        net_warda_minor: string;
    }
>;

type StatementImportDetail = {
    id: number;
    provider: StatementProvider;
    parser_version: string;
    period_start: string;
    period_end: string;
    instrument_label: string;
    instrument_last_four: string | null;
    movement_count: number;
    confirmed_at: string;
    reconciliation: Record<string, string>;
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
        transaction: {
            id: number;
            kind: 'purchase' | 'refund';
            voided_at: string | null;
            category: { id: number; name: string } | null;
        } | null;
    }>;
};

const summaryLabels: Array<[keyof Summary['PEN'], string]> = [
    ['spending_minor', 'Spending'],
    ['refunds_minor', 'Refunds'],
    ['income_minor', 'Income'],
    ['transfers_in_minor', 'Transfers in'],
    ['transfers_out_minor', 'Transfers out'],
    ['warda_deposits_minor', 'WARDA deposits'],
    ['warda_withdrawals_minor', 'WARDA withdrawals'],
    ['net_warda_minor', 'Net WARDA savings'],
];

function reconciliationCurrency(key: string): Currency {
    return key.includes('_usd_') ? 'USD' : 'PEN';
}

export default function StatementImportShow({
    statement_import,
}: {
    statement_import: StatementImportDetail;
}) {
    return (
        <>
            <Head
                title={`${statement_import.provider.toUpperCase()} Statement Import`}
            />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {statement_import.instrument_label}
                            </h1>
                            <Badge variant="outline">
                                {statement_import.provider.toUpperCase()}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {statement_import.period_start} through{' '}
                            {statement_import.period_end} ·{' '}
                            {statement_import.movement_count} movements
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={index()}>
                            <ArrowLeft /> Statement Imports
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    {(['PEN', 'USD'] as const).map((currency) => (
                        <Card key={currency}>
                            <CardHeader>
                                <CardTitle>{currency} summary</CardTitle>
                                <CardDescription>
                                    Classification totals retained for this
                                    statement only.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {summaryLabels.map(([key, label]) => (
                                    <div
                                        key={key}
                                        className="grid gap-1 rounded-lg border p-3"
                                    >
                                        <span className="text-xs text-muted-foreground">
                                            {label}
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {formatMinorUnits(
                                                statement_import.summary[
                                                    currency
                                                ][key],
                                                currency,
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Source reconciliation</CardTitle>
                        <CardDescription>
                            Printed totals retained from the confirmed
                            statement.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {Object.entries(statement_import.reconciliation).map(
                            ([key, value]) => (
                                <div
                                    key={key}
                                    className="grid gap-1 rounded-lg border p-3"
                                >
                                    <span className="text-xs text-muted-foreground capitalize">
                                        {key
                                            .replaceAll('_minor', '')
                                            .replaceAll('_', ' ')}
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatMinorUnits(
                                            value,
                                            reconciliationCurrency(key),
                                        )}
                                    </span>
                                </div>
                            ),
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Statement Movements</CardTitle>
                        <CardDescription>
                            Every posted movement is retained. Only linked
                            Transactions affect spending and review behavior.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[64rem] text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Date
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Description
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Classification
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Direction
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            Amount
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Linked Transaction
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {statement_import.movements.map(
                                        (movement) => (
                                            <tr key={movement.id}>
                                                <td className="px-3 py-3 tabular-nums">
                                                    {movement.occurred_on}
                                                </td>
                                                <td className="px-3 py-3 font-medium">
                                                    {movement.description}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <Badge
                                                        variant={
                                                            movement.classification ===
                                                            'needs_classification'
                                                                ? 'destructive'
                                                                : 'outline'
                                                        }
                                                        className="capitalize"
                                                    >
                                                        {movement.classification.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-3 capitalize">
                                                    {movement.direction}
                                                </td>
                                                <td className="px-3 py-3 text-right tabular-nums">
                                                    {formatMinorUnits(
                                                        movement.amount_minor,
                                                        movement.currency,
                                                    )}
                                                </td>
                                                <td className="px-3 py-3">
                                                    {movement.transaction ? (
                                                        <Link
                                                            href={transactionsIndex(
                                                                {
                                                                    query: {
                                                                        selected:
                                                                            movement
                                                                                .transaction
                                                                                .id,
                                                                    },
                                                                },
                                                            )}
                                                            className="inline-flex items-center gap-1 hover:underline"
                                                        >
                                                            {movement
                                                                .transaction
                                                                .voided_at
                                                                ? 'Voided '
                                                                : ''}
                                                            {
                                                                movement
                                                                    .transaction
                                                                    .kind
                                                            }
                                                            <ExternalLink className="size-3" />
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            None
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
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
