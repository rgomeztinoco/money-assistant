import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FilePlus2, FileText } from 'lucide-react';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    linked_movement_count: number;
    created_movement_count: number;
    excluded_movement_count: number;
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

function totalLabel(key: string): string {
    return key.replaceAll('_minor', '').replaceAll('_', ' ');
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

                <Card className="min-w-0">
                    <CardHeader className="flex-row items-start justify-between gap-3">
                        <div className="grid gap-1.5">
                            <CardTitle>Confirmed statements</CardTitle>
                            <CardDescription>
                                Open an import to review its totals, movements,
                                and linked transactions.
                            </CardDescription>
                        </div>
                        <Badge variant="secondary">
                            {statement_imports.total}{' '}
                            {statement_imports.total === 1
                                ? 'import'
                                : 'imports'}
                        </Badge>
                    </CardHeader>
                    <CardContent
                        className="grid min-w-0 gap-4"
                        data-test="statement-import-list"
                    >
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
                                <Button asChild size="sm" className="mt-1">
                                    <Link href={create()}>
                                        <FilePlus2 /> Import statement
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <>
                                <div
                                    className="grid gap-3 md:hidden"
                                    data-test="statement-import-mobile-list"
                                >
                                    {statement_imports.data.map(
                                        (statementImport) => (
                                            <article
                                                key={statementImport.id}
                                                className="grid min-w-0 gap-4 rounded-lg border p-4"
                                            >
                                                <div className="flex min-w-0 items-start gap-3">
                                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                        <FileText className="size-4" />
                                                    </span>
                                                    <div className="grid min-w-0 flex-1 gap-1">
                                                        <Link
                                                            href={show(
                                                                statementImport.id,
                                                            )}
                                                            prefetch
                                                            className="font-medium break-words hover:underline"
                                                        >
                                                            {
                                                                statementImport.instrument_label
                                                            }
                                                        </Link>
                                                        <p className="text-sm text-muted-foreground tabular-nums">
                                                            {
                                                                statementImport.period_start
                                                            }{' '}
                                                            through{' '}
                                                            {
                                                                statementImport.period_end
                                                            }
                                                        </p>
                                                    </div>
                                                    <Badge variant="outline">
                                                        {statementImport.financial_statement_format.toUpperCase()}
                                                    </Badge>
                                                    <Badge variant="secondary">
                                                        Verified
                                                    </Badge>
                                                </div>

                                                <p className="text-xs text-muted-foreground">
                                                    {
                                                        statementImport.linked_movement_count
                                                    }{' '}
                                                    linked ·{' '}
                                                    {
                                                        statementImport.created_movement_count
                                                    }{' '}
                                                    added ·{' '}
                                                    {
                                                        statementImport.excluded_movement_count
                                                    }{' '}
                                                    excluded
                                                </p>

                                                <div className="grid grid-cols-2 gap-3">
                                                    <div className="grid gap-1 rounded-lg border p-3">
                                                        <span className="text-xs text-muted-foreground">
                                                            Movements
                                                        </span>
                                                        <span className="font-medium tabular-nums">
                                                            {
                                                                statementImport.movement_count
                                                            }
                                                        </span>
                                                    </div>
                                                    <div className="grid gap-1 rounded-lg border p-3">
                                                        <span className="text-xs text-muted-foreground">
                                                            Last four digits
                                                        </span>
                                                        <span className="font-medium tabular-nums">
                                                            {statementImport.instrument_last_four ??
                                                                'Not provided'}
                                                        </span>
                                                    </div>
                                                </div>

                                                <div className="grid gap-1">
                                                    <span className="text-xs text-muted-foreground">
                                                        Statement totals
                                                    </span>
                                                    {identifyingTotals(
                                                        statementImport,
                                                    ).map(([key, value]) => (
                                                        <span
                                                            key={key}
                                                            className="text-sm capitalize tabular-nums"
                                                        >
                                                            {totalLabel(key)}:{' '}
                                                            {formatMinorUnits(
                                                                value,
                                                                totalCurrency(
                                                                    key,
                                                                ),
                                                            )}
                                                        </span>
                                                    ))}
                                                </div>

                                                <div className="flex items-end justify-between gap-3 border-t pt-3">
                                                    <p className="text-xs text-muted-foreground tabular-nums">
                                                        Confirmed{' '}
                                                        {new Date(
                                                            statementImport.confirmed_at,
                                                        ).toLocaleString()}
                                                    </p>
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={show(
                                                                statementImport.id,
                                                            )}
                                                            prefetch
                                                        >
                                                            View details
                                                            <ArrowRight />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </article>
                                        ),
                                    )}
                                </div>

                                <div
                                    className="hidden min-w-0 overflow-hidden rounded-lg border md:block"
                                    data-test="statement-import-table"
                                >
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="pl-6">
                                                    Statement
                                                </TableHead>
                                                <TableHead>Period</TableHead>
                                                <TableHead className="text-center">
                                                    Movements
                                                </TableHead>
                                                <TableHead>
                                                    Statement totals
                                                </TableHead>
                                                <TableHead>Confirmed</TableHead>
                                                <TableHead className="pr-6 text-right">
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {statement_imports.data.map(
                                                (statementImport) => (
                                                    <TableRow
                                                        key={statementImport.id}
                                                    >
                                                        <TableCell className="min-w-52 py-4 pl-6 whitespace-normal">
                                                            <div className="flex min-w-0 items-start gap-3">
                                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                                    <FileText className="size-4" />
                                                                </span>
                                                                <div className="grid min-w-0 gap-1">
                                                                    <Link
                                                                        href={show(
                                                                            statementImport.id,
                                                                        )}
                                                                        prefetch
                                                                        className="font-medium break-words hover:underline"
                                                                    >
                                                                        {
                                                                            statementImport.instrument_label
                                                                        }
                                                                    </Link>
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <Badge variant="outline">
                                                                            {statementImport.financial_statement_format.toUpperCase()}
                                                                        </Badge>
                                                                        <Badge variant="secondary">
                                                                            Verified
                                                                        </Badge>
                                                                        {statementImport.instrument_last_four && (
                                                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                                                Ending{' '}
                                                                                {
                                                                                    statementImport.instrument_last_four
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {
                                                                            statementImport.linked_movement_count
                                                                        }{' '}
                                                                        linked ·{' '}
                                                                        {
                                                                            statementImport.created_movement_count
                                                                        }{' '}
                                                                        added ·{' '}
                                                                        {
                                                                            statementImport.excluded_movement_count
                                                                        }{' '}
                                                                        excluded
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell className="min-w-48 tabular-nums">
                                                            {
                                                                statementImport.period_start
                                                            }{' '}
                                                            through{' '}
                                                            {
                                                                statementImport.period_end
                                                            }
                                                        </TableCell>
                                                        <TableCell className="text-center font-medium tabular-nums">
                                                            {
                                                                statementImport.movement_count
                                                            }
                                                        </TableCell>
                                                        <TableCell className="min-w-52 whitespace-normal">
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
                                                                            className="text-sm capitalize tabular-nums"
                                                                        >
                                                                            {totalLabel(
                                                                                key,
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
                                                        </TableCell>
                                                        <TableCell className="min-w-44 text-sm text-muted-foreground tabular-nums">
                                                            {new Date(
                                                                statementImport.confirmed_at,
                                                            ).toLocaleString()}
                                                        </TableCell>
                                                        <TableCell className="pr-6 text-right">
                                                            <Button
                                                                asChild
                                                                variant="outline"
                                                                size="sm"
                                                            >
                                                                <Link
                                                                    href={show(
                                                                        statementImport.id,
                                                                    )}
                                                                    prefetch
                                                                >
                                                                    View
                                                                    <ArrowRight />
                                                                </Link>
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            </>
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
