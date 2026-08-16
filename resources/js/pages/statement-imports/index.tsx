import { Head, Link } from '@inertiajs/react';
import { FilePlus2, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { create, index, show } from '@/routes/statement_imports';

type StatementImportItem = {
    id: number;
    financial_statement_format: 'bcp' | 'interbank';
    period_start: string;
    period_end: string;
    instrument_label: string;
    instrument_last_four: string | null;
    movement_count: number;
    confirmed_at: string;
    totals: Record<string, string>;
};

type Paginator = {
    data: StatementImportItem[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

function identifyingTotals(statementImport: StatementImportItem) {
    const totals = Object.entries(statementImport.totals);
    const primaryTotals = totals.filter(
        ([key]) =>
            key === 'closing_balance_minor' || key.startsWith('payment_total_'),
    );

    return primaryTotals.length > 0 ? primaryTotals : totals.slice(0, 2);
}

function totalCurrency(key: string): 'PEN' | 'USD' {
    return key.includes('_usd_') ? 'USD' : 'PEN';
}

export default function StatementImportsIndex({
    statement_imports,
}: {
    statement_imports: Paginator;
}) {
    return (
        <>
            <Head title="Statement Imports" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Statement Imports
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Revisit every confirmed BCP and Interbank statement
                            without retaining its source PDF.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={create()}>
                            <FilePlus2 /> Import statement
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Confirmed statements</CardTitle>
                        <CardDescription>
                            {statement_imports.total}{' '}
                            {statement_imports.total === 1
                                ? 'import'
                                : 'imports'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        {statement_imports.data.length === 0 ? (
                            <div className="flex min-h-52 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-6 text-center">
                                <FileText className="size-9 text-muted-foreground" />
                                <div className="grid gap-1">
                                    <p className="font-medium">
                                        No Statement Imports yet
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Import a supported text PDF to backfill
                                        its complete financial activity.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full min-w-[58rem] text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">
                                                Format
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                Period
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                Instrument
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Movements
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                Totals
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                Confirmed
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {statement_imports.data.map(
                                            (statementImport) => (
                                                <tr key={statementImport.id}>
                                                    <td className="px-4 py-3 font-medium uppercase">
                                                        <Link
                                                            href={show(
                                                                statementImport.id,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {
                                                                statementImport.financial_statement_format
                                                            }
                                                        </Link>
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {
                                                            statementImport.period_start
                                                        }{' '}
                                                        –{' '}
                                                        {
                                                            statementImport.period_end
                                                        }
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {
                                                            statementImport.instrument_label
                                                        }
                                                        {statementImport.instrument_last_four && (
                                                            <span className="text-muted-foreground">
                                                                {' '}
                                                                ····{' '}
                                                                {
                                                                    statementImport.instrument_last_four
                                                                }
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {
                                                            statementImport.movement_count
                                                        }
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="grid gap-1">
                                                            {identifyingTotals(
                                                                statementImport,
                                                            ).map(
                                                                ([
                                                                    key,
                                                                    value,
                                                                ]) => (
                                                                    <span
                                                                        key={
                                                                            key
                                                                        }
                                                                        className="text-xs capitalize tabular-nums"
                                                                    >
                                                                        {key
                                                                            .replaceAll(
                                                                                '_minor',
                                                                                '',
                                                                            )
                                                                            .replaceAll(
                                                                                '_',
                                                                                ' ',
                                                                            )}
                                                                        :{' '}
                                                                        {formatMinorUnits(
                                                                            value,
                                                                            totalCurrency(
                                                                                key,
                                                                            ),
                                                                        )}
                                                                    </span>
                                                                ),
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {new Date(
                                                            statementImport.confirmed_at,
                                                        ).toLocaleString()}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {statement_imports.last_page > 1 && (
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-sm text-muted-foreground">
                                    Page {statement_imports.current_page} of{' '}
                                    {statement_imports.last_page}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        asChild={Boolean(
                                            statement_imports.prev_page_url,
                                        )}
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            !statement_imports.prev_page_url
                                        }
                                    >
                                        {statement_imports.prev_page_url ? (
                                            <Link
                                                href={
                                                    statement_imports.prev_page_url
                                                }
                                            >
                                                Previous
                                            </Link>
                                        ) : (
                                            <span>Previous</span>
                                        )}
                                    </Button>
                                    <Button
                                        asChild={Boolean(
                                            statement_imports.next_page_url,
                                        )}
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            !statement_imports.next_page_url
                                        }
                                    >
                                        {statement_imports.next_page_url ? (
                                            <Link
                                                href={
                                                    statement_imports.next_page_url
                                                }
                                            >
                                                Next
                                            </Link>
                                        ) : (
                                            <span>Next</span>
                                        )}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

StatementImportsIndex.layout = {
    breadcrumbs: [{ title: 'Statement Imports', href: index() }],
};
