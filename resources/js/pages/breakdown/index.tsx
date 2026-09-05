import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Check,
    ChevronRight,
    ChevronsUpDown,
    CircleAlert,
    CircleCheck,
    Filter,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { update as updateClassification } from '@/actions/App/Http/Controllers/BreakdownTransactionClassificationController';
import { SourceCoverage } from '@/components/source-coverage';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    incomeSourceLabel,
    movementDescription,
    transferPurposeLabel,
} from '@/lib/money-movement';
import { index as breakdownIndex } from '@/routes/breakdown';
import type { Currency } from '@/types';
import { CategoryBreakdown, DailyChart } from './charts';
import { groupCategoryOptions } from './classification-select';
import { selectionUrl } from './links';
import { ManualTransactionDialog } from './manual-transaction-dialog';
import { PeriodControls } from './period-controls';
import { TransactionDetails } from './transaction-details';
import type {
    BreakdownProps,
    BreakdownTransaction,
    CurrencyAmounts,
} from './types';

const currencies = ['PEN', 'USD'] satisfies Currency[];
const focusLabels = {
    net_spending: 'Net spending',
    income: 'Income',
    savings: 'To savings',
} satisfies Record<NonNullable<BreakdownProps['filters']['focus']>, string>;

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
                    <h2 className="font-semibold">{props.period.label}</h2>
                </div>
                <span className="text-right text-xs text-muted-foreground tabular-nums">
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
                            <span className="text-xs font-semibold tracking-wider text-muted-foreground">
                                {currency}
                            </span>
                            <dl>
                                <dt className="text-sm text-muted-foreground">
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
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
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
                        <h3 className="font-semibold">Categorization</h3>
                        <p className="text-sm text-muted-foreground">
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
                                <span className="text-sm text-muted-foreground">
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
                                <span className="text-xs text-muted-foreground tabular-nums">
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
                className="grid shrink-0 gap-2 text-xs sm:grid-cols-2"
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
            className="h-7 rounded-full px-2.5 text-xs"
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
                    <h2 className="font-semibold">Merchants</h2>
                    <p className="text-sm text-muted-foreground">
                        Ranked by transaction count. Select one to drill in.
                    </p>
                </div>
            </div>
            {props.merchants.length === 0 ? (
                <p className="border-y py-6 text-center text-sm text-muted-foreground">
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
                                    className={`flex items-center justify-between gap-3 px-1 py-3 hover:bg-muted/50 ${selected ? 'bg-primary/5' : ''}`}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium">
                                            {merchant.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
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

function transactionClassification(transaction: BreakdownTransaction): string {
    if (transaction.kind === 'income') {
        return incomeSourceLabel(transaction.income_source);
    }

    if (transaction.kind === 'transfer') {
        return transferPurposeLabel(transaction.transfer_purpose);
    }

    return transaction.category?.name ?? 'Uncategorized';
}

function InlineCategory({
    transaction,
    props,
}: {
    transaction: BreakdownTransaction;
    props: BreakdownProps;
}) {
    const currentCategoryId = transaction.category?.id.toString() ?? '';
    const [categoryId, setCategoryId] = useState(currentCategoryId);
    const [processingAction, setProcessingAction] = useState<
        'once' | 'rule' | null
    >(null);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const hasPendingCategory = categoryId !== currentCategoryId;
    const selectedCategory = props.category_options.find(
        (option) => option.id.toString() === categoryId,
    );
    const normalizedSearch = search.trim().toLocaleLowerCase();
    const categoryGroups = groupCategoryOptions(props.category_options)
        .map((group) => ({
            ...group,
            options: group.options.filter((option) =>
                `${option.parent?.name ?? ''} ${option.name}`
                    .toLocaleLowerCase()
                    .includes(normalizedSearch),
            ),
        }))
        .filter((group) => group.options.length > 0);
    const showUncategorized = 'uncategorized'.includes(normalizedSearch);

    function submitCategory({ applyToMatching }: { applyToMatching: boolean }) {
        if (!hasPendingCategory || (applyToMatching && categoryId === '')) {
            return;
        }

        setProcessingAction(applyToMatching ? 'rule' : 'once');

        router.put(
            updateClassification(transaction.id),
            {
                category_id: categoryId === '' ? null : Number(categoryId),
                apply_to_matching: applyToMatching,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setProcessingAction(null),
            },
        );
    }

    if (
        (transaction.kind !== 'spending' && transaction.kind !== 'refund') ||
        transaction.split !== null
    ) {
        return (
            <span className="text-sm text-muted-foreground">
                {transaction.split === null
                    ? transactionClassification(transaction)
                    : 'Category split'}
            </span>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-auto min-h-8 w-full min-w-0 justify-between border-transparent bg-transparent px-2 py-1.5 text-left font-normal whitespace-normal shadow-none hover:border-input sm:max-w-64"
                        aria-label={`Category for ${transaction.description}`}
                        disabled={processingAction !== null}
                    />
                }
            >
                <span className="min-w-0 break-words">
                    {selectedCategory?.name ?? 'Uncategorized'}
                </span>
                <ChevronsUpDown className="size-3.5 shrink-0 opacity-50" />
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(24rem,calc(100vw-2rem))] gap-0 p-0"
            >
                <div className="relative border-b p-2">
                    <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={search}
                        aria-label="Search categories"
                        placeholder="Search categories"
                        className="pl-9"
                        onChange={(event) =>
                            setSearch(event.currentTarget.value)
                        }
                    />
                </div>
                <div
                    className="max-h-64 overflow-y-auto p-1"
                    role="listbox"
                    aria-label="Categories"
                >
                    {showUncategorized && (
                        <Button
                            type="button"
                            variant="ghost"
                            role="option"
                            aria-selected={categoryId === ''}
                            className="h-auto w-full justify-start px-2 py-2 text-left whitespace-normal"
                            onClick={() => setCategoryId('')}
                        >
                            <Check
                                className={`size-4 shrink-0 ${categoryId === '' ? 'opacity-100' : 'opacity-0'}`}
                            />
                            Uncategorized
                        </Button>
                    )}
                    {categoryGroups.map((group) => (
                        <section key={group.key} aria-label={group.label}>
                            <p className="px-2 pt-2 pb-1 text-xs font-medium text-muted-foreground">
                                {group.label}
                            </p>
                            {group.options.map((option) => {
                                const optionValue = option.id.toString();

                                return (
                                    <Button
                                        key={option.id}
                                        type="button"
                                        variant="ghost"
                                        role="option"
                                        aria-selected={
                                            categoryId === optionValue
                                        }
                                        className="h-auto w-full justify-start px-2 py-2 text-left whitespace-normal"
                                        onClick={() =>
                                            setCategoryId(optionValue)
                                        }
                                    >
                                        <Check
                                            className={`size-4 shrink-0 ${categoryId === optionValue ? 'opacity-100' : 'opacity-0'}`}
                                        />
                                        <span className="wrap-break-word">
                                            {option.name}
                                        </span>
                                    </Button>
                                );
                            })}
                        </section>
                    ))}
                    {!showUncategorized && categoryGroups.length === 0 && (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No categories found.
                        </p>
                    )}
                </div>
                {hasPendingCategory && (
                    <div
                        className="flex items-center justify-end gap-1.5 border-t p-2"
                        data-test={`category-confirmation-${transaction.id}`}
                    >
                        <Button
                            type="button"
                            size="sm"
                            data-test={`apply-category-once-${transaction.id}`}
                            disabled={processingAction !== null}
                            onClick={() =>
                                submitCategory({ applyToMatching: false })
                            }
                        >
                            {processingAction === 'once'
                                ? 'Applying…'
                                : 'Apply once'}
                        </Button>
                        {categoryId !== '' && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                data-test={`create-merchant-rule-${transaction.id}`}
                                disabled={processingAction !== null}
                                onClick={() =>
                                    submitCategory({ applyToMatching: true })
                                }
                            >
                                {processingAction === 'rule'
                                    ? 'Creating…'
                                    : 'Create rule'}
                            </Button>
                        )}
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

function TransactionTable({ props }: { props: BreakdownProps }) {
    const transactions = props.transaction_days.flatMap(
        (day) => day.transactions,
    );

    if (transactions.length === 0) {
        return (
            <div className="grid min-h-80 place-items-center p-8 text-center">
                <div className="grid gap-2">
                    <p className="font-medium">No transactions</p>
                    <p className="text-sm text-muted-foreground">
                        Change the period or clear a filter.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <Table className="block sm:table">
            <TableHeader className="sticky top-0 z-10 hidden bg-background sm:table-header-group">
                <TableRow className="hover:bg-background">
                    <TableHead className="pl-4">Description</TableHead>
                    <TableHead>Category</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead className="w-10 pr-4">
                        <span className="sr-only">Open</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody className="block divide-y sm:table-row-group sm:divide-y-0">
                {transactions.map((transaction) => {
                    const isMoneyIn = transaction.direction === 'credit';
                    const DirectionIcon = isMoneyIn
                        ? ArrowDownLeft
                        : ArrowUpRight;

                    return (
                        <TableRow
                            key={transaction.id}
                            className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-2 gap-y-2 border-0 p-3 sm:table-row sm:border-b sm:p-0"
                        >
                            <TableCell className="order-1 min-w-0 p-0 whitespace-normal sm:table-cell sm:min-w-52 sm:py-3 sm:pl-4">
                                <div className="flex items-start gap-3">
                                    <span
                                        className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full ${isMoneyIn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}
                                    >
                                        <DirectionIcon className="size-4" />
                                    </span>
                                    <span className="grid min-w-0 gap-0.5">
                                        <span className="font-medium wrap-break-word">
                                            {transaction.description}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {transaction.occurred_on} ·{' '}
                                            {movementDescription({
                                                kind: transaction.kind,
                                                transferPurpose:
                                                    transaction.transfer_purpose,
                                            })}
                                        </span>
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell className="order-4 col-span-3 min-w-0 p-0 whitespace-normal sm:table-cell sm:min-w-44 sm:p-2">
                                <InlineCategory
                                    transaction={transaction}
                                    props={props}
                                />
                            </TableCell>
                            <TableCell
                                className={`order-2 p-0 text-right font-semibold tabular-nums sm:p-2 ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                            >
                                {isMoneyIn ? '+' : '−'}
                                {formatMinorUnits(
                                    transaction.amount_minor,
                                    transaction.currency,
                                )}
                            </TableCell>
                            <TableCell className="order-3 p-0 text-right sm:p-2 sm:pr-4">
                                <Button asChild size="icon" variant="ghost">
                                    <Link
                                        href={selectionUrl({
                                            currencyFilter:
                                                props.currency_filter,
                                            period: props.period,
                                            category: props.filters.category,
                                            day: props.filters.day,
                                            focus: props.filters.focus,
                                            merchant: props.filters.merchant,
                                            attention: props.filters.attention,
                                            selected: transaction.id,
                                        })}
                                        preserveScroll
                                        data-test={`breakdown-transaction-${transaction.id}`}
                                        aria-label={`Open ${transaction.description}`}
                                    >
                                        <ChevronRight />
                                    </Link>
                                </Button>
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}

export default function BreakdownIndex(props: BreakdownProps) {
    const selectedCategory = selectedCategoryLabel(props);
    const selectedTransaction = props.transaction_days
        .flatMap((day) => day.transactions)
        .find((transaction) => transaction.id === props.filters.selected);
    const hasFilters =
        props.currency_filter !== null ||
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

    return (
        <>
            <Head title="Breakdown" />
            <main className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6 xl:overflow-hidden">
                <div
                    className="flex shrink-0 flex-wrap items-center gap-2"
                    data-test="breakdown-filter-bar"
                >
                    <span className="text-sm font-medium">Currency</span>
                    {(
                        [
                            { value: null, label: 'All' },
                            { value: 'PEN', label: 'PEN' },
                            { value: 'USD', label: 'USD' },
                        ] satisfies Array<{
                            value: Currency | null;
                            label: string;
                        }>
                    ).map((option) => (
                        <Button
                            key={option.label}
                            asChild
                            size="sm"
                            variant={
                                props.currency_filter === option.value
                                    ? 'secondary'
                                    : 'ghost'
                            }
                        >
                            <Link
                                href={selectionUrl({
                                    currencyFilter: option.value,
                                    period: props.period,
                                    category: props.filters.category,
                                    day: props.filters.day,
                                    focus: props.filters.focus,
                                    merchant: props.filters.merchant,
                                    attention: props.filters.attention,
                                    selected: null,
                                })}
                                preserveScroll
                            >
                                {option.label}
                            </Link>
                        </Button>
                    ))}
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
                                    currencyFilter: null,
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
                    <div className="ml-auto">
                        <ManualTransactionDialog
                            currency={props.currency_filter ?? 'PEN'}
                            today={props.today}
                        />
                    </div>
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
                                defaultValue={initialOverviewTab}
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
                        <div className="flex h-12 shrink-0 items-center justify-between border-b px-4">
                            <h2 className="font-semibold">Transactions</h2>
                            <Badge
                                variant="secondary"
                                className="h-5 min-w-5 px-1.5"
                            >
                                {props.transaction_days.reduce(
                                    (count, day) =>
                                        count + day.transactions.length,
                                    0,
                                )}
                            </Badge>
                        </div>
                        <div
                            className="h-full min-h-0 min-w-0 flex-1 overflow-y-auto"
                            data-test="breakdown-transactions-scroll"
                        >
                            <TransactionTable props={props} />
                        </div>
                    </Card>
                </div>
            </main>

            <Dialog
                open={selectedTransaction !== undefined}
                onOpenChange={(open) => {
                    if (!open) {
                        router.visit(closeDetailsHref, {
                            preserveScroll: true,
                        });
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            {selectedTransaction?.description ??
                                'Transaction details'}
                        </DialogTitle>
                        <DialogDescription>
                            Edit the record, manage a split, or inspect its
                            source.
                        </DialogDescription>
                    </DialogHeader>
                    {selectedTransaction !== undefined && (
                        <TransactionDetails
                            key={`${selectedTransaction.id}-${selectedTransaction.category?.id ?? 'none'}-${selectedTransaction.split?.length ?? 0}`}
                            transaction={selectedTransaction}
                            categoryOptions={props.category_options}
                            incomeSourceOptions={props.income_source_options}
                            closeHref={closeDetailsHref}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

BreakdownIndex.layout = (props: BreakdownProps) => ({
    breadcrumbs: [
        {
            title: 'Breakdown',
            href: breakdownIndex(),
        },
    ],
    headerActions: (
        <PeriodControls
            currencyFilter={props.currency_filter}
            period={props.period}
            today={props.today}
        />
    ),
    viewportConstrained: true,
});
