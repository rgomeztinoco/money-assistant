import { Head, Link, router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Filter, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { CurrencyFilter } from '@/components/currency-filter';
import { PeriodControls } from '@/components/period-controls';
import { SourceCoverage } from '@/components/source-coverage';
import { TransactionActionDialog } from '@/components/transaction-action-dialog';
import { TransactionCategorySelect } from '@/components/transaction-category-select';
import { TransactionListFilterControls } from '@/components/transaction-list-filters';
import type { TransactionListFilters } from '@/components/transaction-list-filters';
import { TransactionTable } from '@/components/transaction-table';
import type { TransactionAction } from '@/components/transaction-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useReportView } from '@/hooks/use-report-view';
import { formatReportingPeriod } from '@/lib/date-presentation';
import {
    currencyUnitsToMinorUnits,
    formatMinorUnits,
} from '@/lib/format-minor-units';
import { reportingQuery, reportingSelection } from '@/lib/reporting-query';
import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency, ReportingPeriodSelection } from '@/types';
import { CategoryBreakdown, DailyChart } from './charts';
import { pickerCategoryOptions } from './classification-select';
import { selectionUrl } from './links';
import { ManualTransactionDialog } from './manual-transaction-dialog';
import type { BreakdownProps, CurrencyAmounts } from './types';

const currencies = ['PEN', 'USD'] satisfies Currency[];
const focusLabels = {
    net_spending: 'Net spending',
    income: 'Income',
    savings: 'To savings',
} satisfies Record<NonNullable<BreakdownProps['filters']['focus']>, string>;

function breakdownReportingHref({
    currencyFilter,
    selection,
}: {
    currencyFilter: Currency | null;
    selection: ReportingPeriodSelection;
}): string {
    return breakdownIndex.url({
        query: reportingQuery(currencyFilter, selection),
    });
}

function shownCurrencies(currencyFilter: Currency | null): Currency[] {
    return currencyFilter === null ? currencies : [currencyFilter];
}

function selectedCategoryLabel(props: BreakdownProps): string | null {
    if (props.filters.category === null) {
        return null;
    }

    if (props.filters.category === 'uncategorized') {
        return 'Uncategorized';
    }

    const categoryId = Number(props.filters.category);

    return (
        props.category_options.find((option) => option.id === categoryId)
            ?.path ?? `Category ${props.filters.category}`
    );
}

function CurrencyAmountsList({
    amounts,
    currencyFilter,
}: {
    amounts: CurrencyAmounts;
    currencyFilter: Currency | null;
}) {
    return (
        <span className="grid justify-items-end gap-0.5">
            {shownCurrencies(currencyFilter)
                .filter((currency) => amounts[currency] !== '0')
                .map((currency) => (
                    <span key={currency} className="tabular-nums">
                        {formatMinorUnits(amounts[currency], currency)}
                    </span>
                ))}
        </span>
    );
}

function CombinedCurrencyAmounts({
    amounts,
    currencyFilter,
}: {
    amounts: CurrencyAmounts;
    currencyFilter: Currency | null;
}) {
    const visibleCurrencies = shownCurrencies(currencyFilter);
    const nonZeroCurrencies = visibleCurrencies.filter(
        (currency) => amounts[currency] !== '0',
    );
    const displayedCurrencies =
        nonZeroCurrencies.length > 0 ? nonZeroCurrencies : visibleCurrencies;

    return (
        <span className="font-semibold whitespace-nowrap tabular-nums">
            {displayedCurrencies.map((currency, index) => (
                <span key={currency}>
                    {index > 0 && ' + '}
                    {formatMinorUnits(amounts[currency], currency)}
                </span>
            ))}
        </span>
    );
}

function BreakdownSummary({ props }: { props: BreakdownProps }) {
    const visibleCurrencies = shownCurrencies(props.currency_filter);
    const uncategorizedTransactionCount = visibleCurrencies.reduce(
        (total, currency) =>
            total +
            props.categorization[currency].uncategorized_transaction_count,
        0,
    );
    const categorizationTransactionCount = visibleCurrencies.reduce(
        (total, currency) =>
            total + props.categorization[currency].transaction_count,
        0,
    );
    const uncategorizedPercentage =
        categorizationTransactionCount === 0
            ? 0
            : (uncategorizedTransactionCount / categorizationTransactionCount) *
              100;
    const uncategorizedAmounts = {
        PEN: props.categorization.PEN.uncategorized_amount_minor,
        USD: props.categorization.USD.uncategorized_amount_minor,
    } satisfies CurrencyAmounts;
    const needsCategorization = uncategorizedTransactionCount > 0;

    return (
        <section
            className="grid content-start gap-4"
            data-test="breakdown-summary"
        >
            <header className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="type-section-title">
                        {formatReportingPeriod(props.period)}
                    </h2>
                </div>
                <span className="text-right type-meta tabular-nums">
                    {props.coverage.transaction_count}{' '}
                    {props.coverage.transaction_count === 1
                        ? 'transaction'
                        : 'transactions'}
                </span>
            </header>

            <div
                className={`grid divide-y border-y ${props.currency_filter === null ? 'sm:grid-cols-2 sm:divide-x sm:divide-y-0' : ''}`}
            >
                {visibleCurrencies.map((currency) => (
                    <section
                        key={currency}
                        className="grid gap-4 px-1 py-4 sm:px-4"
                    >
                        <div className="grid gap-1">
                            <span className="type-meta tracking-wider">
                                {currency}
                            </span>
                            <dl>
                                <dt className="type-body text-muted-foreground">
                                    Net spending
                                </dt>
                                <dd className="text-2xl font-semibold tracking-tight tabular-nums">
                                    {formatMinorUnits(
                                        props.summary[currency]
                                            .net_spending_minor,
                                        currency,
                                    )}
                                </dd>
                            </dl>
                        </div>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 type-body">
                            <dt className="text-muted-foreground">Income</dt>
                            <dd className="text-right font-medium tabular-nums">
                                {formatMinorUnits(
                                    props.summary[currency].income_minor,
                                    currency,
                                )}
                            </dd>
                            <dt className="text-muted-foreground">Savings</dt>
                            <dd className="text-right font-medium tabular-nums">
                                {formatMinorUnits(
                                    props.summary[currency]
                                        .moved_to_savings_minor,
                                    currency,
                                )}
                            </dd>
                        </dl>
                    </section>
                ))}
            </div>

            <section className="grid gap-3">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h3 className="type-section-title">Categorization</h3>
                        <p className="type-subtitle">
                            Spending and refunds that still need a category.
                        </p>
                    </div>
                    <Badge
                        variant={
                            uncategorizedTransactionCount > 0
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {uncategorizedTransactionCount > 0 ? (
                            <CircleAlert />
                        ) : (
                            <CircleCheck />
                        )}
                        {uncategorizedTransactionCount > 0
                            ? `${uncategorizedTransactionCount} uncategorized`
                            : 'Complete'}
                    </Badge>
                </div>
                {(() => {
                    const content = (
                        <>
                            <span className="grid min-w-0 gap-1">
                                <span className="type-body text-muted-foreground">
                                    {needsCategorization
                                        ? `${uncategorizedTransactionCount} ${uncategorizedTransactionCount === 1 ? 'transaction' : 'transactions'}`
                                        : categorizationTransactionCount > 0
                                          ? 'All categorized'
                                          : 'No spending or refunds'}
                                </span>
                                <span
                                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                                    data-test="breakdown-categorization-bar"
                                >
                                    <span
                                        className={`block h-full rounded-full ${needsCategorization ? 'bg-destructive' : 'bg-emerald-500'}`}
                                        style={{
                                            width: `${needsCategorization ? Math.min(100, uncategorizedPercentage) : categorizationTransactionCount > 0 ? 100 : 0}%`,
                                        }}
                                    />
                                </span>
                            </span>
                            <span className="grid shrink-0 justify-items-end gap-0.5">
                                <CombinedCurrencyAmounts
                                    amounts={uncategorizedAmounts}
                                    currencyFilter={props.currency_filter}
                                />
                                <span className="type-meta tabular-nums">
                                    {Number(uncategorizedPercentage.toFixed(2))}
                                    % of transactions
                                </span>
                            </span>
                        </>
                    );
                    const className =
                        'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 border-y px-1 py-3';

                    return needsCategorization ? (
                        <Link
                            href={selectionUrl({
                                currencyFilter: props.currency_filter,
                                period: props.period,
                                category: 'uncategorized',
                                day: null,
                                focus: null,
                                merchant: null,
                                attention: false,
                                selected: null,
                            })}
                            preserveScroll
                            className={`${className} transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden`}
                            data-test="breakdown-review-uncategorized"
                        >
                            {content}
                        </Link>
                    ) : (
                        <div className={className}>{content}</div>
                    );
                })()}
            </section>

            <SourceCoverage
                source={props.coverage.source}
                className="grid shrink-0 gap-2 type-meta sm:grid-cols-2"
                detailed
                gmailMissingLabel="Connect Gmail for ongoing activity"
            />
        </section>
    );
}

function RemovableFilter({
    label,
    removeLabel,
    href,
}: {
    label: string;
    removeLabel: string;
    href: ReturnType<typeof selectionUrl>;
}) {
    return (
        <Button
            asChild
            size="sm"
            variant="secondary"
            className="h-7 rounded-full px-2.5 type-meta"
        >
            <Link href={href} preserveScroll aria-label={removeLabel}>
                {label}
                <X />
            </Link>
        </Button>
    );
}

function MerchantRanking({ props }: { props: BreakdownProps }) {
    return (
        <section className="grid content-start gap-3">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="type-section-title">Merchants</h2>
                    <p className="type-subtitle">
                        Ranked by transaction count. Select one to drill in.
                    </p>
                </div>
            </div>
            {props.merchants.length === 0 ? (
                <p className="border-y py-6 text-center type-body text-muted-foreground">
                    No merchants in this selection.
                </p>
            ) : (
                <ol className="divide-y border-y">
                    {props.merchants.map((merchant) => {
                        const selected =
                            props.filters.merchant === merchant.name;

                        return (
                            <li key={merchant.name}>
                                <Link
                                    href={selectionUrl({
                                        currencyFilter: props.currency_filter,
                                        period: props.period,
                                        category: props.filters.category,
                                        day: props.filters.day,
                                        focus: props.filters.focus,
                                        merchant: selected
                                            ? null
                                            : merchant.name,
                                        attention: props.filters.attention,
                                        selected: null,
                                    })}
                                    preserveScroll
                                    data-test={`breakdown-merchant-${merchant.name}`}
                                    className={`flex items-center justify-between gap-3 px-1 py-3 type-row hover:bg-muted/50 ${selected ? 'bg-primary/5' : ''}`}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate">
                                            {merchant.name}
                                        </span>
                                        <span className="type-meta">
                                            {merchant.transaction_count}{' '}
                                            {merchant.transaction_count === 1
                                                ? 'transaction'
                                                : 'transactions'}
                                        </span>
                                    </span>
                                    <CurrencyAmountsList
                                        amounts={merchant.amount_minor}
                                        currencyFilter={props.currency_filter}
                                    />
                                </Link>
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function useBreakdownTransactions(
    props: BreakdownProps,
    onAction: (transactionId: number, action: TransactionAction) => void,
) {
    const [search, setSearch] = useState('');
    const [appliedFilters, setAppliedFilters] =
        useState<TransactionListFilters>({
            amount_min: null,
            amount_max: null,
            kinds: [],
        });
    const scopeKey = [
        props.currency_filter,
        props.period.date_from,
        props.period.date_to,
        props.filters.category,
        props.filters.day,
        props.filters.focus,
        props.filters.merchant,
        props.filters.attention,
    ].join(':');

    const [pageState, setPageState] = useState({ scopeKey, page: 1 });
    const page = pageState.scopeKey === scopeKey ? pageState.page : 1;

    const transactions = props.transaction_days.flatMap(
        (day) => day.transactions,
    );
    const searchText = search.trim().toLocaleLowerCase();
    const minimum = appliedFilters.amount_min
        ? currencyUnitsToMinorUnits(appliedFilters.amount_min)
        : null;
    const maximum = appliedFilters.amount_max
        ? currencyUnitsToMinorUnits(appliedFilters.amount_max)
        : null;
    const filteredTransactions = transactions.filter((transaction) => {
        const amount = BigInt(transaction.amount_minor);
        const magnitude = amount < 0n ? -amount : amount;

        return (
            (searchText === '' ||
                transaction.description
                    .toLocaleLowerCase()
                    .includes(searchText) ||
                transaction.id.toString() === searchText.replace(/^#/, '')) &&
            (minimum === null || magnitude >= minimum) &&
            (maximum === null || magnitude <= maximum) &&
            (appliedFilters.kinds.length === 0 ||
                appliedFilters.kinds.includes(transaction.kind))
        );
    });
    const categoryOptions = props.category_options.map((option) => ({
        id: option.id,
        name: option.name,
        path: option.path,
        parent_id: option.parent?.id ?? null,
        parent_name: option.parent?.name ?? null,
    }));

    return {
        count: filteredTransactions.length,
        controls: (
            <TransactionListFilterControls
                search={search}
                filters={appliedFilters}
                instantSearch
                onSearch={(value) => {
                    setSearch(value);
                    setPageState({ scopeKey, page: 1 });
                }}
                onApply={(filters) => {
                    setAppliedFilters(filters);
                    setPageState({ scopeKey, page: 1 });
                }}
            />
        ),
        table: (
            <TransactionTable
                transactions={filteredTransactions.slice(
                    (page - 1) * 50,
                    page * 50,
                )}
                total={filteredTransactions.length}
                page={page}
                onPageChange={(nextPage) =>
                    setPageState({ scopeKey, page: nextPage })
                }
                onAction={(transaction, action) =>
                    onAction(transaction.id, action)
                }
                renderCategory={(transaction) => (
                    <TransactionCategorySelect
                        key={`${transaction.id}-${transaction.category?.id ?? 'none'}`}
                        transaction={{
                            ...transaction,
                            has_split: transaction.split !== null,
                        }}
                        categoryOptions={categoryOptions}
                    />
                )}
                rowTestId={(transaction) =>
                    `breakdown-transaction-${transaction.id}`
                }
            />
        ),
    };
}

export default function BreakdownIndex(props: BreakdownProps) {
    const [activeAction, setActiveAction] = useState<TransactionAction>('edit');
    const [closingAction, setClosingAction] = useState(false);
    const tableScroll = useRef<number | null>(null);
    const transactionList = useBreakdownTransactions(
        props,
        (transactionId, action) => {
            setClosingAction(false);
            setActiveAction(action);
            tableScroll.current =
                document.querySelector<HTMLElement>(
                    '[data-test="breakdown-transactions-scroll"]',
                )?.scrollTop ?? null;
            router.visit(
                selectionUrl({
                    currencyFilter: props.currency_filter,
                    period: props.period,
                    category: props.filters.category,
                    day: props.filters.day,
                    focus: props.filters.focus,
                    merchant: props.filters.merchant,
                    attention: props.filters.attention,
                    selected: transactionId,
                }).url,
                { preserveScroll: true, preserveState: true },
            );
        },
    );
    const selectedCategory = selectedCategoryLabel(props);
    const selectedTransaction = props.transaction_days
        .flatMap((day) => day.transactions)
        .find((transaction) => transaction.id === props.filters.selected);
    const hasFilters =
        props.filters.category !== null ||
        props.filters.day !== null ||
        props.filters.focus !== null ||
        props.filters.merchant !== null ||
        props.filters.attention;
    const initialOverviewTab = props.filters.merchant
        ? 'merchants'
        : props.filters.category
          ? 'categories'
          : 'summary';
    const [overviewTab, setOverviewTab] = useReportView(
        ['summary', 'categories', 'merchants'] as const,
        initialOverviewTab,
    );
    const closeDetailsHref = selectionUrl({
        currencyFilter: props.currency_filter,
        period: props.period,
        category: props.filters.category,
        day: props.filters.day,
        focus: props.filters.focus,
        merchant: props.filters.merchant,
        attention: props.filters.attention,
        selected: null,
    });

    function closeEditor(): void {
        setClosingAction(true);
        router.visit(closeDetailsHref, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setClosingAction(false),
            onError: () => setClosingAction(false),
            onCancel: () => setClosingAction(false),
            onFinish: () => {
                if (tableScroll.current !== null) {
                    const position = tableScroll.current;
                    requestAnimationFrame(() => {
                        const table = document.querySelector<HTMLElement>(
                            '[data-test="breakdown-transactions-scroll"]',
                        );

                        if (table) {
                            table.scrollTop = position;
                        }
                    });
                    tableScroll.current = null;
                }
            },
        });
    }

    return (
        <>
            <Head title="Breakdown" />
            <main className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6 xl:overflow-hidden">
                <div
                    className="flex shrink-0 flex-wrap items-center gap-2"
                    data-test="breakdown-filter-bar"
                >
                    <CurrencyFilter
                        value={props.currency_filter}
                        options={[
                            {
                                value: null,
                                label: 'All',
                                testId: 'reporting-currency-all',
                            },
                            {
                                value: 'PEN',
                                label: 'PEN',
                                testId: 'reporting-currency-pen',
                            },
                            {
                                value: 'USD',
                                label: 'USD',
                                testId: 'reporting-currency-usd',
                            },
                        ]}
                        href={(currencyFilter) =>
                            selectionUrl({
                                currencyFilter,
                                period: props.period,
                                category: props.filters.category,
                                day: props.filters.day,
                                focus: props.filters.focus,
                                merchant: props.filters.merchant,
                                attention: props.filters.attention,
                                selected: null,
                            }).url
                        }
                    />
                    {selectedCategory !== null && (
                        <RemovableFilter
                            label={`Category: ${selectedCategory}`}
                            removeLabel={`Remove category filter: ${selectedCategory}`}
                            href={selectionUrl({
                                currencyFilter: props.currency_filter,
                                period: props.period,
                                category: null,
                                day: props.filters.day,
                                focus: props.filters.focus,
                                merchant: props.filters.merchant,
                                attention: props.filters.attention,
                                selected: null,
                            })}
                        />
                    )}
                    {props.filters.day !== null && (
                        <RemovableFilter
                            label={`Day: ${props.filters.day}`}
                            removeLabel={`Remove day filter: ${props.filters.day}`}
                            href={selectionUrl({
                                currencyFilter: props.currency_filter,
                                period: props.period,
                                category: props.filters.category,
                                day: null,
                                focus: props.filters.focus,
                                merchant: props.filters.merchant,
                                attention: props.filters.attention,
                                selected: null,
                            })}
                        />
                    )}
                    {props.filters.focus !== null && (
                        <RemovableFilter
                            label={`Focus: ${focusLabels[props.filters.focus]}`}
                            removeLabel={`Remove focus filter: ${focusLabels[props.filters.focus]}`}
                            href={selectionUrl({
                                currencyFilter: props.currency_filter,
                                period: props.period,
                                category: props.filters.category,
                                day: props.filters.day,
                                focus: null,
                                merchant: props.filters.merchant,
                                attention: props.filters.attention,
                                selected: null,
                            })}
                        />
                    )}
                    {props.filters.merchant !== null && (
                        <RemovableFilter
                            label={`Merchant: ${props.filters.merchant}`}
                            removeLabel={`Remove merchant filter: ${props.filters.merchant}`}
                            href={selectionUrl({
                                currencyFilter: props.currency_filter,
                                period: props.period,
                                category: props.filters.category,
                                day: props.filters.day,
                                focus: props.filters.focus,
                                merchant: null,
                                attention: props.filters.attention,
                                selected: null,
                            })}
                        />
                    )}
                    {props.filters.attention && (
                        <RemovableFilter
                            label="Needs attention"
                            removeLabel="Remove needs attention filter"
                            href={selectionUrl({
                                currencyFilter: props.currency_filter,
                                period: props.period,
                                category: props.filters.category,
                                day: props.filters.day,
                                focus: props.filters.focus,
                                merchant: props.filters.merchant,
                                attention: false,
                                selected: null,
                            })}
                        />
                    )}
                    {hasFilters && (
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={selectionUrl({
                                    currencyFilter: props.currency_filter,
                                    period: props.period,
                                    category: null,
                                    day: null,
                                    focus: null,
                                    merchant: null,
                                    attention: false,
                                    selected: null,
                                })}
                            >
                                <Filter /> Clear filters
                            </Link>
                        </Button>
                    )}
                    <div className="ml-auto">{transactionList.controls}</div>
                </div>

                <div className="grid min-h-0 min-w-0 flex-1 gap-4 xl:grid-cols-[minmax(20rem,0.8fr)_minmax(36rem,1.2fr)] xl:grid-rows-[minmax(0,1fr)] xl:items-stretch xl:overflow-hidden">
                    <Card
                        className="min-h-0 min-w-0 gap-0 overflow-hidden py-0 xl:h-full"
                        data-test="breakdown-overview-card"
                    >
                        <CardContent className="flex h-full min-h-0 flex-col gap-6 p-4 sm:p-6">
                            <DailyChart
                                currencyFilter={props.currency_filter}
                                period={props.period}
                                days={props.days}
                                granularity={props.chart_granularity}
                                filters={props.filters}
                            />
                            <Tabs
                                value={overviewTab}
                                onValueChange={setOverviewTab}
                                className="min-h-0 flex-1 flex-col gap-3 overflow-hidden"
                            >
                                <TabsList className="grid h-8 w-full shrink-0 grid-cols-3">
                                    <TabsTrigger
                                        value="summary"
                                        data-test="breakdown-tab-summary"
                                    >
                                        Summary
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="categories"
                                        data-test="breakdown-tab-categories"
                                    >
                                        Categories
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="merchants"
                                        data-test="breakdown-tab-merchants"
                                    >
                                        Merchants
                                    </TabsTrigger>
                                </TabsList>
                                <TabsContent
                                    value="summary"
                                    className="h-0 min-h-0 overflow-y-auto"
                                >
                                    <BreakdownSummary props={props} />
                                </TabsContent>
                                <TabsContent
                                    value="categories"
                                    className="h-0 min-h-0 overflow-y-auto"
                                    data-test="breakdown-categories-scroll"
                                >
                                    <CategoryBreakdown
                                        currencyFilter={props.currency_filter}
                                        period={props.period}
                                        groups={props.category_groups}
                                        filters={props.filters}
                                    />
                                </TabsContent>
                                <TabsContent
                                    value="merchants"
                                    className="h-0 min-h-0 overflow-y-auto"
                                    data-test="breakdown-merchants-scroll"
                                >
                                    <MerchantRanking props={props} />
                                </TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>

                    <Card
                        className="min-h-0 min-w-0 gap-0 overflow-hidden py-0 xl:h-full"
                        data-test="breakdown-transactions-card"
                    >
                        <div
                            className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b p-4"
                            data-test="breakdown-transactions-header"
                        >
                            <div className="flex items-center gap-2">
                                <h2 className="type-section-title">
                                    Transactions
                                </h2>
                                <Badge
                                    variant="secondary"
                                    data-test="transaction-matching-count"
                                >
                                    {transactionList.count} matching{' '}
                                    {transactionList.count === 1
                                        ? 'Transaction'
                                        : 'Transactions'}
                                </Badge>
                            </div>
                            <ManualTransactionDialog
                                currency={props.currency_filter ?? 'PEN'}
                                today={props.today}
                                categoryOptions={props.category_options}
                            />
                        </div>
                        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                            {transactionList.table}
                        </div>
                    </Card>
                </div>
            </main>

            {selectedTransaction && !closingAction && (
                <TransactionActionDialog
                    transaction={selectedTransaction}
                    action={activeAction}
                    today={props.today}
                    categoryOptions={pickerCategoryOptions(
                        props.category_options,
                    )}
                    splitCategoryOptions={props.category_options}
                    onClose={closeEditor}
                />
            )}
        </>
    );
}

BreakdownIndex.layout = (props: BreakdownProps) => ({
    breadcrumbs: [
        {
            title: 'Breakdown',
            href: breakdownIndex({
                query: reportingQuery(
                    props.currency_filter,
                    reportingSelection(props.period),
                ),
            }),
        },
    ],
    headerActions: (
        <PeriodControls
            period={props.period}
            today={props.today}
            href={(selection) =>
                breakdownReportingHref({
                    currencyFilter: props.currency_filter,
                    selection,
                })
            }
        />
    ),
    viewportConstrained: true,
});
