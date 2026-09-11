import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    categoryBreakdownUrl,
    periodBreakdownUrl,
} from '@/lib/transaction-filter-url';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';
import type { Finding, Period, TrendReport } from './types';

type FindingScope = 'all' | Finding['kind'];

const ledgerColumns =
    'grid-cols-[repeat(3,minmax(0,1fr))_2rem] md:grid-cols-[minmax(7.5rem,1.2fr)_repeat(3,minmax(4.5rem,.65fr))_minmax(4.5rem,.6fr)_minmax(4.5rem,.7fr)_3.5rem]';

function absoluteAmount(amount: string): string {
    return amount.startsWith('-') ? amount.slice(1) : amount;
}

function findingName(finding: Finding): string {
    return finding.kind === 'category'
        ? finding.category.name
        : finding.merchant;
}

function findingIdentity(finding: Finding): string {
    if (finding.kind === 'category') {
        return `${finding.currency}:category:${finding.category.id ?? 'uncategorized'}`;
    }

    return `${finding.currency}:merchant:${finding.merchant}`;
}

function findingTestSegment(finding: Finding): string {
    if (finding.kind === 'category') {
        return `category-${finding.category.id ?? 'uncategorized'}`;
    }

    const merchantKey = finding.merchant
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, '-')
        .replace(/^-|-$/g, '');

    return `merchant-${merchantKey}`;
}

function findingTestId(finding: Finding): string {
    return `${finding.currency.toLowerCase()}-${findingTestSegment(finding)}`;
}

function findingUrl(finding: Finding, period: Period) {
    if (finding.kind === 'category') {
        return categoryBreakdownUrl({
            currency: finding.currency,
            period,
            categoryId: finding.category.id,
        });
    }

    return periodBreakdownUrl({
        currency: finding.currency,
        period,
        merchant: finding.merchant,
    });
}

function FindingChange({ finding }: { finding: Finding }) {
    const decreased = finding.change_minor.startsWith('-');
    const Icon = decreased ? ArrowDownRight : ArrowUpRight;
    const testId = findingTestId(finding);

    return (
        <span
            className={cn(
                'inline-flex items-center justify-end gap-1 font-semibold whitespace-nowrap tabular-nums',
                decreased ? 'text-chart-2' : 'text-chart-1',
            )}
            data-direction={decreased ? 'down' : 'up'}
            data-test={`trend-change-${testId}`}
        >
            <Icon className="size-3.5" />
            <span className="sr-only">{decreased ? 'Down' : 'Up'} </span>
            {formatMinorUnits(
                absoluteAmount(finding.change_minor),
                finding.currency,
            )}
        </span>
    );
}

function TrendSparkline({ finding }: { finding: Finding }) {
    const values = finding.period_totals_minor.map(Number);
    const minimum = Math.min(...values);
    const maximum = Math.max(...values);
    const spread = maximum - minimum;
    const width = 72;
    const height = 24;
    const padding = 2;
    const points = values
        .map((value, index) => {
            const x =
                padding +
                (index / Math.max(values.length - 1, 1)) *
                    (width - padding * 2);
            const y =
                spread === 0
                    ? height / 2
                    : padding +
                      ((maximum - value) / spread) * (height - padding * 2);

            return `${x},${y}`;
        })
        .join(' ');
    const decreased = finding.change_minor.startsWith('-');
    const lastPoint = points.split(' ').at(-1)?.split(',') ?? ['0', '0'];
    const colorClass = decreased ? 'text-chart-2' : 'text-chart-1';

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            className={cn('h-6 w-[4.5rem]', colorClass)}
            role="img"
            aria-label={`${findingName(finding)} trend over seven periods`}
            data-test={`trend-sparkline-${findingTestId(finding)}`}
        >
            <polyline
                points={points}
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
            />
            <circle
                cx={lastPoint[0]}
                cy={lastPoint[1]}
                r="2.5"
                fill="currentColor"
            />
        </svg>
    );
}

export function ChangeLedger({
    currencyFilter,
    period,
    reports,
}: {
    currencyFilter: Currency | null;
    period: Period;
    reports: TrendReport[];
}) {
    const [scope, setScope] = useState<FindingScope>('all');
    const findings = reports.flatMap((report) => report.findings);
    const hasActivity = reports.some((report) => report.summary !== null);
    const ledgerSegment = currencyFilter?.toLowerCase() ?? 'all';
    const visibleFindings = findings.filter(
        (finding) => scope === 'all' || finding.kind === scope,
    );

    return (
        <Card
            className="flex min-h-0 min-w-0 flex-col gap-0 overflow-hidden py-0 xl:h-full"
            data-test={`trends-ledger-${ledgerSegment}`}
        >
            <div className="flex min-h-12 shrink-0 flex-col gap-2 border-b px-4 py-3 sm:h-12 sm:flex-row sm:items-center sm:justify-between sm:py-0">
                <div className="flex items-center gap-2">
                    <h2 className="font-semibold">Changes by impact</h2>
                    <Badge variant="outline">{currencyFilter ?? 'All'}</Badge>
                    <Badge variant="secondary">{visibleFindings.length}</Badge>
                </div>
                <div
                    className="flex flex-wrap gap-1"
                    aria-label="Filter change ledger"
                >
                    {(
                        [
                            ['all', 'All'],
                            ['category', 'Categories'],
                            ['merchant', 'Merchants'],
                        ] as const
                    ).map(([value, label]) => (
                        <Button
                            key={value}
                            type="button"
                            size="sm"
                            variant={scope === value ? 'secondary' : 'ghost'}
                            aria-pressed={scope === value}
                            data-test={`trends-${ledgerSegment}-filter-${value}`}
                            onClick={() => setScope(value)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>
            </div>

            <div
                className="min-h-0 flex-1 overflow-y-auto"
                data-test="trends-ledger-scroll"
            >
                {!hasActivity ? (
                    <div className="grid justify-items-center gap-2 p-8 text-center">
                        <p className="font-medium">
                            {currencyFilter === null
                                ? 'No activity'
                                : `No ${currencyFilter} activity`}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Nothing was recorded in this period.
                        </p>
                    </div>
                ) : visibleFindings.length === 0 ? (
                    <div className="grid justify-items-center gap-2 p-8 text-center">
                        <p className="font-medium">No material findings</p>
                        <p className="max-w-md text-sm text-muted-foreground">
                            No material{' '}
                            {scope === 'all' ? 'Category or merchant' : scope}{' '}
                            change appears in this comparison.
                        </p>
                    </div>
                ) : (
                    <>
                        <div
                            className={cn(
                                'sticky top-0 z-10 hidden h-10 items-center gap-2 border-b bg-background px-4 text-xs font-medium text-muted-foreground md:grid',
                                ledgerColumns,
                            )}
                            aria-hidden="true"
                        >
                            <span>Category / merchant</span>
                            <span className="text-right">This period</span>
                            <span className="text-right">Typical</span>
                            <span className="text-right">Change</span>
                            <span className="text-right">Frequency</span>
                            <span className="text-center">Trend</span>
                            <span className="text-center">Actions</span>
                        </div>

                        <ol>
                            {visibleFindings.map((finding) => {
                                const testId = findingTestId(finding);

                                return (
                                    <li
                                        key={findingIdentity(finding)}
                                        className={cn(
                                            'grid items-center gap-x-2 gap-y-3 border-b px-4 py-3 last:border-b-0',
                                            ledgerColumns,
                                        )}
                                    >
                                        <span className="col-span-4 row-start-1 min-w-0 md:col-span-1 md:row-auto">
                                            <span className="block truncate font-medium">
                                                {findingName(finding)}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {finding.kind === 'category'
                                                    ? 'Category'
                                                    : 'Merchant'}
                                                {currencyFilter === null &&
                                                    ` · ${finding.currency}`}
                                            </span>
                                            {finding.unusual_transaction !==
                                                null && (
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    Unusual:{' '}
                                                    {
                                                        finding
                                                            .unusual_transaction
                                                            .description
                                                    }{' '}
                                                    ·{' '}
                                                    {formatMinorUnits(
                                                        finding
                                                            .unusual_transaction
                                                            .amount_minor,
                                                        finding.currency,
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                        <span className="col-start-1 row-start-2 min-w-0 md:col-start-2 md:row-auto md:text-right">
                                            <span className="block text-[0.6875rem] text-muted-foreground md:hidden">
                                                This period
                                            </span>
                                            <span className="block truncate text-sm font-medium tabular-nums">
                                                {formatMinorUnits(
                                                    finding.current_total_minor,
                                                    finding.currency,
                                                )}
                                            </span>
                                        </span>
                                        <span className="col-start-2 row-start-2 min-w-0 text-right md:col-start-3 md:row-auto">
                                            <span className="block text-[0.6875rem] text-muted-foreground md:hidden">
                                                Typical
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground tabular-nums">
                                                {formatMinorUnits(
                                                    finding.typical_total_minor,
                                                    finding.currency,
                                                )}
                                            </span>
                                        </span>
                                        <span className="col-start-3 row-start-2 min-w-0 text-right md:col-start-4 md:row-auto">
                                            <span className="block text-[0.6875rem] text-muted-foreground md:hidden">
                                                Change
                                            </span>
                                            <FindingChange finding={finding} />
                                        </span>
                                        <span
                                            className="col-span-2 col-start-1 row-start-3 text-sm tabular-nums md:col-span-1 md:col-start-5 md:row-auto md:text-right"
                                            data-test={`trend-frequency-${testId}`}
                                        >
                                            <span className="text-[0.6875rem] text-muted-foreground md:sr-only">
                                                Frequency{' '}
                                            </span>
                                            {finding.current_transaction_count}
                                            <span className="text-muted-foreground">
                                                {' '}
                                                vs{' '}
                                                {
                                                    finding.typical_transaction_count
                                                }
                                            </span>
                                        </span>
                                        <span className="col-span-2 col-start-3 row-start-3 justify-self-end md:col-span-1 md:col-start-6 md:row-auto md:justify-self-center">
                                            <TrendSparkline finding={finding} />
                                        </span>
                                        <Button
                                            asChild
                                            size="icon"
                                            variant="ghost"
                                            className="col-start-4 row-start-2 size-8 justify-self-end md:col-start-7 md:row-auto"
                                        >
                                            <Link
                                                href={findingUrl(
                                                    finding,
                                                    period,
                                                )}
                                                data-test={`trend-breakdown-${testId}`}
                                                aria-label={`Open ${findingName(finding)} in Breakdown`}
                                            >
                                                <ArrowRight />
                                            </Link>
                                        </Button>
                                    </li>
                                );
                            })}
                        </ol>
                    </>
                )}
            </div>

            <p
                className="shrink-0 border-t p-4 text-xs text-muted-foreground"
                data-test="trends-ledger-note"
            >
                Category and merchant views overlap. Their changes should not be
                added together.
            </p>
        </Card>
    );
}
