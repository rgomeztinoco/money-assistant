import { Link } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    ChevronDown,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Separator } from '@/components/ui/separator';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    categoryBreakdownUrl,
    periodBreakdownUrl,
} from '@/lib/transaction-filter-url';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';
import type { Finding, Period, Summary } from './types';

type FindingScope = 'all' | Finding['kind'];

const ledgerColumns =
    'grid-cols-[repeat(3,minmax(0,1fr))_2rem] md:grid-cols-[minmax(10rem,1.3fr)_minmax(6rem,.7fr)_minmax(6rem,.7fr)_minmax(7rem,.75fr)_minmax(6rem,.6fr)_2rem]';

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
        return `category:${finding.category.id ?? 'uncategorized'}`;
    }

    return `merchant:${finding.merchant}`;
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

function findingUrl(
    finding: Finding,
    period: Period,
    includeUnusualTransaction = true,
) {
    const selected = includeUnusualTransaction
        ? finding.unusual_transaction?.id
        : undefined;

    if (finding.kind === 'category') {
        return categoryBreakdownUrl({
            currency: finding.currency,
            period,
            categoryId: finding.category.id,
            selected,
        });
    }

    return periodBreakdownUrl({
        currency: finding.currency,
        period,
        merchant: finding.merchant,
        selected,
    });
}

function FindingChange({ finding }: { finding: Finding }) {
    const decreased = finding.change_minor.startsWith('-');
    const Icon = decreased ? ArrowDownRight : ArrowUpRight;
    const testSegment = findingTestSegment(finding);

    return (
        <span
            className={cn(
                'inline-flex items-center justify-end gap-1 font-semibold whitespace-nowrap tabular-nums',
                decreased ? 'text-chart-2' : 'text-chart-1',
            )}
            data-direction={decreased ? 'down' : 'up'}
            data-test={`trend-change-${testSegment}`}
        >
            <Icon className="size-4" />
            <span className="sr-only">{decreased ? 'Down' : 'Up'} </span>
            {formatMinorUnits(
                absoluteAmount(finding.change_minor),
                finding.currency,
            )}
        </span>
    );
}

function ComparisonBars({ finding }: { finding: Finding }) {
    const maximum = Math.max(
        Math.abs(Number(finding.current_total_minor)),
        Math.abs(Number(finding.typical_total_minor)),
        1,
    );

    return (
        <div
            className="grid gap-4"
            aria-label={`${findingName(finding)} Net Spending comparison`}
        >
            {[
                {
                    label: 'This period',
                    amount: finding.current_total_minor,
                    className: 'bg-chart-1',
                },
                {
                    label: 'Typical',
                    amount: finding.typical_total_minor,
                    className: 'bg-chart-2',
                },
            ].map((item) => (
                <div key={item.label} className="grid gap-2">
                    <div className="flex justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">
                            {item.label}
                        </span>
                        <span className="font-medium tabular-nums">
                            {formatMinorUnits(item.amount, finding.currency)}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className={cn(
                                'h-full rounded-full',
                                item.className,
                            )}
                            style={{
                                width: `${(Math.abs(Number(item.amount)) / maximum) * 100}%`,
                            }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

function FindingEvidence({
    finding,
    period,
    comparisonPeriods,
}: {
    finding: Finding;
    period: Period;
    comparisonPeriods: Period[];
}) {
    const testSegment = findingTestSegment(finding);

    return (
        <div
            className="grid gap-5 p-4 sm:p-5"
            data-test={`trend-evidence-${testSegment}`}
        >
            <div className="grid gap-5 lg:grid-cols-2">
                <ComparisonBars finding={finding} />

                <div className="grid content-start gap-4">
                    <div className="flex items-end justify-between gap-3 rounded-lg bg-background p-3">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Transaction frequency
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums">
                                {finding.current_transaction_count}
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    vs {finding.typical_transaction_count}{' '}
                                    typical
                                </span>
                            </p>
                        </div>
                    </div>

                    {finding.unusual_transaction !== null && (
                        <div className="rounded-lg border border-chart-1/25 bg-chart-1/5 p-3">
                            <p className="text-xs font-medium text-muted-foreground">
                                Unusual Transaction
                            </p>
                            <div className="mt-1 flex flex-wrap items-baseline justify-between gap-2">
                                <p className="text-sm font-medium">
                                    {finding.unusual_transaction.description}
                                </p>
                                <p className="font-semibold tabular-nums">
                                    {formatMinorUnits(
                                        finding.unusual_transaction
                                            .amount_minor,
                                        finding.currency,
                                    )}
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <Separator />

            <div className="flex flex-col items-start justify-between gap-4 lg:flex-row lg:items-end">
                <div className="grid gap-2">
                    <p className="text-xs text-muted-foreground">
                        Typical is based on these equivalent periods
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {comparisonPeriods.map((comparisonPeriod, index) => (
                            <Link
                                key={comparisonPeriod.date_from}
                                href={findingUrl(
                                    finding,
                                    comparisonPeriod,
                                    false,
                                )}
                                data-test={`trend-finding-${testSegment}-comparison-${index}`}
                                className="rounded-md border bg-background px-2 py-1 text-xs hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            >
                                {comparisonPeriod.label}
                            </Link>
                        ))}
                    </div>
                </div>

                <Button asChild size="sm" variant="outline">
                    <Link
                        href={findingUrl(finding, period)}
                        data-test={`trend-breakdown-${testSegment}`}
                    >
                        Open in Breakdown
                        <ArrowRight data-icon="inline-end" />
                    </Link>
                </Button>
            </div>
        </div>
    );
}

function ImpactMagnitude({
    finding,
    maximumImpact,
}: {
    finding: Finding;
    maximumImpact: number;
}) {
    const decreased = finding.change_minor.startsWith('-');
    const width = (Math.abs(Number(finding.change_minor)) / maximumImpact) * 48;

    return (
        <span
            className="relative block h-5 w-full"
            aria-label={`${findingName(finding)} has ${decreased ? 'lower' : 'higher'} impact`}
        >
            <span className="absolute inset-y-0 left-1/2 w-px bg-border" />
            <span
                className={cn(
                    'absolute top-1.5 h-2 rounded-sm',
                    decreased ? 'right-1/2 bg-chart-2' : 'left-1/2 bg-chart-1',
                )}
                style={{ width: `${width}%` }}
            />
        </span>
    );
}

export function ChangeLedger({
    currency,
    period,
    comparisonPeriods,
    summary,
    findings,
}: {
    currency: Currency;
    period: Period;
    comparisonPeriods: Period[];
    summary: Summary | null;
    findings: Finding[];
}) {
    const [scope, setScope] = useState<FindingScope>('all');
    const [expandedFindings, setExpandedFindings] = useState<Set<string>>(
        new Set(),
    );
    const visibleFindings = findings.filter(
        (finding) => scope === 'all' || finding.kind === scope,
    );
    const maximumImpact = Math.max(
        ...visibleFindings.map((finding) =>
            Math.abs(Number(finding.change_minor)),
        ),
        1,
    );

    function setFindingExpanded(key: string, open: boolean): void {
        setExpandedFindings((current) => {
            const next = new Set(current);

            if (open) {
                next.add(key);
            } else {
                next.delete(key);
            }

            return next;
        });
    }

    return (
        <section className="min-w-0 overflow-hidden rounded-xl border">
            <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <h2 className="font-semibold">Changes by impact</h2>
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
                            data-test={`trends-filter-${value}`}
                            onClick={() => setScope(value)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>
            </div>

            {summary === null ? (
                <div className="grid justify-items-center gap-2 p-8 text-center">
                    <TrendingUp className="size-8 text-muted-foreground" />
                    <p className="font-medium">
                        No {currency} activity this month to date
                    </p>
                    <p className="max-w-md text-sm text-muted-foreground">
                        The change ledger will appear after this period records
                        Transactions.
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
                            'hidden items-center gap-3 border-b bg-muted/20 px-4 py-2 text-xs font-medium text-muted-foreground md:grid',
                            ledgerColumns,
                        )}
                        aria-hidden="true"
                    >
                        <span>Category / merchant</span>
                        <span className="text-right">This period</span>
                        <span className="text-right">Typical</span>
                        <span className="text-right">Change</span>
                        <span className="text-center">Lower / higher</span>
                        <span />
                    </div>

                    <ol>
                        {visibleFindings.map((finding) => {
                            const identity = findingIdentity(finding);
                            const testSegment = findingTestSegment(finding);
                            const expanded = expandedFindings.has(identity);

                            return (
                                <li
                                    key={identity}
                                    className="border-b last:border-b-0"
                                >
                                    <Collapsible
                                        open={expanded}
                                        onOpenChange={(open) =>
                                            setFindingExpanded(identity, open)
                                        }
                                    >
                                        <CollapsibleTrigger asChild>
                                            <button
                                                type="button"
                                                className={cn(
                                                    'group grid w-full items-center gap-x-3 gap-y-3 px-4 py-3 text-left transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden focus-visible:ring-inset data-[state=open]:bg-muted/40',
                                                    ledgerColumns,
                                                )}
                                                data-test={`trend-toggle-${testSegment}`}
                                            >
                                                <span className="col-span-3 col-start-1 row-start-1 min-w-0 md:col-span-1">
                                                    <span className="block truncate font-medium">
                                                        {findingName(finding)}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {finding.kind ===
                                                        'category'
                                                            ? 'Category'
                                                            : 'Merchant'}
                                                    </span>
                                                </span>
                                                <span className="col-start-1 row-start-2 min-w-0 md:col-start-2 md:row-start-1 md:text-right">
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
                                                <span className="col-start-2 row-start-2 min-w-0 text-right md:col-start-3 md:row-start-1">
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
                                                <span className="col-start-3 row-start-2 min-w-0 text-right md:col-start-4 md:row-start-1">
                                                    <span className="block text-[0.6875rem] text-muted-foreground md:hidden">
                                                        Change
                                                    </span>
                                                    <FindingChange
                                                        finding={finding}
                                                    />
                                                </span>
                                                <span className="col-span-3 col-start-1 row-start-3 md:col-span-1 md:col-start-5 md:row-start-1">
                                                    <ImpactMagnitude
                                                        finding={finding}
                                                        maximumImpact={
                                                            maximumImpact
                                                        }
                                                    />
                                                </span>
                                                <ChevronDown className="col-start-4 row-start-1 size-4 justify-self-end text-muted-foreground transition-transform group-data-[state=open]:rotate-180 md:col-start-6" />
                                            </button>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent className="bg-muted/20">
                                            <FindingEvidence
                                                finding={finding}
                                                period={period}
                                                comparisonPeriods={
                                                    comparisonPeriods
                                                }
                                            />
                                        </CollapsibleContent>
                                    </Collapsible>
                                </li>
                            );
                        })}
                    </ol>
                </>
            )}

            <p className="border-t p-4 text-xs text-muted-foreground">
                Category and merchant views overlap. Their changes should not be
                added together.
            </p>
        </section>
    );
}
