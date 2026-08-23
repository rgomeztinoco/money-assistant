import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    ChevronDown,
    Store,
    WalletCards,
} from 'lucide-react';
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
import { movementDescription } from '@/lib/money-movement';
import { index as breakdownIndex } from '@/routes/breakdown';
import { CategoryChart, DailyChart } from './charts';
import { selectionUrl } from './links';
import { ManualTransactionDialog } from './manual-transaction-dialog';
import { PeriodControls } from './period-controls';
import { TransactionDetails } from './transaction-details';
import type {
    BreakdownProps,
    BreakdownTransaction,
    BreakdownTransactionDay,
} from './types';

function SummaryCard({
    label,
    amount,
    currency,
    primary = false,
}: {
    label: string;
    amount: string;
    currency: BreakdownProps['currency'];
    primary?: boolean;
}) {
    return (
        <Card
            className={primary ? 'border-primary/30 bg-primary/5' : undefined}
        >
            <CardHeader className="gap-2">
                <CardDescription>{label}</CardDescription>
                <CardTitle
                    className={
                        primary
                            ? 'text-3xl tabular-nums'
                            : 'text-2xl tabular-nums'
                    }
                >
                    {formatMinorUnits(amount, currency)}
                </CardTitle>
            </CardHeader>
        </Card>
    );
}

function TransactionRow({
    transaction,
    props,
}: {
    transaction: BreakdownTransaction;
    props: BreakdownProps;
}) {
    const selected = props.filters.selected === transaction.id;
    const isMoneyIn = transaction.direction === 'credit';
    const DirectionIcon = isMoneyIn ? ArrowDownLeft : ArrowUpRight;
    const closeHref = selectionUrl({
        currency: props.currency,
        period: props.period,
        category: props.filters.category,
        day: props.filters.day,
        focus: props.filters.focus,
        merchant: props.filters.merchant,
        attention: props.filters.attention,
        selected: null,
    });

    return (
        <li
            className={`overflow-hidden rounded-lg border ${selected ? 'border-primary/50 bg-primary/5' : 'bg-background'}`}
        >
            <Link
                href={
                    selected
                        ? closeHref
                        : selectionUrl({
                              currency: props.currency,
                              period: props.period,
                              category: props.filters.category,
                              day: props.filters.day,
                              focus: props.filters.focus,
                              merchant: props.filters.merchant,
                              attention: props.filters.attention,
                              selected: transaction.id,
                          })
                }
                preserveScroll
                data-test={`breakdown-transaction-${transaction.id}`}
                className="grid gap-3 p-4 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden sm:grid-cols-[auto_minmax(0,1fr)_auto_auto] sm:items-center"
            >
                <span
                    className={`flex size-9 items-center justify-center rounded-full ${isMoneyIn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}
                >
                    <DirectionIcon className="size-4" />
                </span>
                <span className="grid min-w-0 gap-1">
                    <span className="truncate font-medium">
                        {transaction.description}
                    </span>
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span>
                            {movementDescription({
                                kind: transaction.kind,
                                transferPurpose: transaction.transfer_purpose,
                            })}
                        </span>
                        {transaction.kind === 'income' && (
                            <span>Income Source editable</span>
                        )}
                        {movementSupportsInlineCategory(transaction) && (
                            <span>
                                {transaction.category?.name ?? 'Uncategorized'}
                            </span>
                        )}
                        {transaction.split !== null && (
                            <Badge variant="outline">Split</Badge>
                        )}
                    </span>
                </span>
                <span
                    className={`font-semibold whitespace-nowrap tabular-nums ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                >
                    {isMoneyIn ? '+' : '−'}
                    {formatMinorUnits(
                        transaction.amount_minor,
                        transaction.currency,
                    )}
                </span>
                <ChevronDown
                    className={`size-4 text-muted-foreground transition-transform ${selected ? 'rotate-180' : ''}`}
                />
            </Link>

            {selected && (
                <TransactionDetails
                    key={`${transaction.id}-${transaction.category?.id ?? 'none'}-${transaction.income_source ?? 'none'}-${transaction.split?.length ?? 0}`}
                    transaction={transaction}
                    categoryOptions={props.category_options}
                    incomeSourceOptions={props.income_source_options}
                    closeHref={closeHref}
                />
            )}
        </li>
    );
}

function movementSupportsInlineCategory(
    transaction: BreakdownTransaction,
): boolean {
    return transaction.kind === 'spending' || transaction.kind === 'refund';
}

function TransactionDayGroup({
    day,
    props,
}: {
    day: BreakdownTransactionDay;
    props: BreakdownProps;
}) {
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-end justify-between gap-3 border-b pb-2">
                <div>
                    <h3 className="font-semibold">{day.date}</h3>
                    <p className="text-xs text-muted-foreground">
                        {day.transactions.length}{' '}
                        {day.transactions.length === 1
                            ? 'Transaction'
                            : 'Transactions'}
                    </p>
                </div>
                <dl className="flex flex-wrap justify-end gap-x-4 gap-y-1 text-xs">
                    <div className="flex gap-1">
                        <dt className="text-muted-foreground">Net Spending</dt>
                        <dd className="font-semibold tabular-nums">
                            {formatMinorUnits(
                                day.net_spending_minor,
                                props.currency,
                            )}
                        </dd>
                    </div>
                    {day.income_minor !== '0' && (
                        <div className="flex gap-1">
                            <dt className="text-muted-foreground">Income</dt>
                            <dd className="font-semibold tabular-nums">
                                {formatMinorUnits(
                                    day.income_minor,
                                    props.currency,
                                )}
                            </dd>
                        </div>
                    )}
                    {day.moved_to_savings_minor !== '0' && (
                        <div className="flex gap-1">
                            <dt className="text-muted-foreground">
                                Moved to Savings
                            </dt>
                            <dd className="font-semibold tabular-nums">
                                {formatMinorUnits(
                                    day.moved_to_savings_minor,
                                    props.currency,
                                )}
                            </dd>
                        </div>
                    )}
                </dl>
            </div>
            <ul className="grid gap-2">
                {day.transactions.map((transaction) => (
                    <TransactionRow
                        key={transaction.id}
                        transaction={transaction}
                        props={props}
                    />
                ))}
            </ul>
        </section>
    );
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

const focusLabels: Record<
    NonNullable<BreakdownProps['filters']['focus']>,
    string
> = {
    net_spending: 'Net Spending',
    income: 'Income',
    savings: 'Moved to Savings',
};

export default function BreakdownIndex(props: BreakdownProps) {
    const {
        currency,
        period,
        coverage,
        summary,
        filters,
        category_groups: categoryGroups,
        days,
        merchants,
        transaction_days: transactionDays,
        today,
    } = props;
    const categoryLabel = selectedCategoryLabel(props);
    const hasFilters =
        filters.category !== null ||
        filters.day !== null ||
        filters.focus !== null ||
        filters.merchant !== null ||
        filters.attention;

    return (
        <>
            <Head title="Breakdown" />

            <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="grid gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <WalletCards className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Breakdown
                            </h1>
                        </div>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Understand one period first, then drill through
                            Categories, days, merchants, and supporting
                            Transactions without leaving this page.
                        </p>
                    </div>
                    <ManualTransactionDialog
                        currency={currency}
                        today={today}
                    />
                </header>

                <PeriodControls
                    currency={currency}
                    period={period}
                    coverage={coverage}
                />

                <section className="grid gap-4 md:grid-cols-3">
                    <SummaryCard
                        label="Net Spending"
                        amount={summary.net_spending_minor}
                        currency={currency}
                        primary
                    />
                    <SummaryCard
                        label="Income"
                        amount={summary.income_minor}
                        currency={currency}
                    />
                    <SummaryCard
                        label="Moved to Savings"
                        amount={summary.moved_to_savings_minor}
                        currency={currency}
                    />
                </section>

                {hasFilters && (
                    <section className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
                        <span className="font-medium">Detail filters</span>
                        {categoryLabel !== null && (
                            <Badge variant="secondary">
                                Category: {categoryLabel}
                            </Badge>
                        )}
                        {filters.day !== null && (
                            <Badge variant="secondary">
                                Day: {filters.day}
                            </Badge>
                        )}
                        {filters.focus !== null && (
                            <Badge variant="secondary">
                                Focus: {focusLabels[filters.focus]}
                            </Badge>
                        )}
                        {filters.merchant !== null && (
                            <Badge variant="secondary">
                                Merchant: {filters.merchant}
                            </Badge>
                        )}
                        {filters.attention && (
                            <Badge variant="secondary">Needs your input</Badge>
                        )}
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={selectionUrl({
                                    currency,
                                    period,
                                    category: null,
                                    day: null,
                                    focus: null,
                                    merchant: null,
                                    attention: false,
                                    selected: null,
                                })}
                                preserveScroll
                            >
                                Clear filters
                            </Link>
                        </Button>
                    </section>
                )}

                <section className="grid gap-4 xl:grid-cols-2">
                    <CategoryChart
                        currency={currency}
                        period={period}
                        groups={categoryGroups}
                        selectedCategory={filters.category}
                        selectedDay={filters.day}
                    />
                    <DailyChart
                        currency={currency}
                        period={period}
                        days={days}
                        selectedCategory={filters.category}
                        selectedDay={filters.day}
                    />
                </section>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,0.7fr)_minmax(0,1.3fr)]">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <Store className="size-5 text-muted-foreground" />
                                {hasFilters && (
                                    <Badge variant="secondary">Filtered</Badge>
                                )}
                            </div>
                            <CardTitle>Merchants</CardTitle>
                            <CardDescription>
                                Exact merchant descriptions in the current
                                supporting selection.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {merchants.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    No spending merchants appear in this
                                    selection.
                                </p>
                            ) : (
                                <ol className="grid gap-2">
                                    {merchants.map((merchant) => (
                                        <li
                                            key={merchant.name}
                                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">
                                                    {merchant.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {merchant.transaction_count}{' '}
                                                    {merchant.transaction_count ===
                                                    1
                                                        ? 'Transaction'
                                                        : 'Transactions'}
                                                </span>
                                            </span>
                                            <span className="font-semibold whitespace-nowrap tabular-nums">
                                                {formatMinorUnits(
                                                    merchant.amount_minor,
                                                    currency,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Supporting Transactions</CardTitle>
                            <CardDescription>
                                Grouped by day with daily subtotals. Expand one
                                to classify, edit, inspect its source, or split
                                its amount by Category.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-6">
                            {transactionDays.length === 0 ? (
                                <div className="grid justify-items-center gap-3 rounded-lg border border-dashed p-8 text-center">
                                    <p className="font-medium">
                                        No supporting Transactions
                                    </p>
                                    <p className="max-w-md text-sm text-muted-foreground">
                                        Change the period or clear one of the
                                        linked chart filters.
                                    </p>
                                    <Button asChild variant="outline">
                                        <Link
                                            href={breakdownIndex({
                                                query: { currency },
                                            })}
                                        >
                                            Reset Breakdown
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                transactionDays.map((day) => (
                                    <TransactionDayGroup
                                        key={day.date}
                                        day={day}
                                        props={props}
                                    />
                                ))
                            )}
                        </CardContent>
                    </Card>
                </section>
            </main>
        </>
    );
}

BreakdownIndex.layout = {
    breadcrumbs: [
        {
            title: 'Breakdown',
            href: breakdownIndex(),
        },
    ],
};
