import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    MoreHorizontal,
    Plus,
    Search,
    SlidersHorizontal,
    Store,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { CategoryOption, MerchantRule } from '@/types';
import { prototypeRules } from './prototype-data';

// Throwaway table-first merchant rule manager prototype for comparison on the existing Merchant Rules route.

type RuleSortKey = 'merchant' | 'category' | 'kind' | 'currency' | 'status';

type SortDirection = 'ascending' | 'descending';
type KindFilter = 'all' | 'spending' | 'refund';
type CurrencyFilter = 'all' | 'PEN' | 'USD';

type RuleRow = {
    categoryPath: string;
    rule: MerchantRule;
};

function normalizeSearch(value: string) {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase();
}

function SortIcon({
    active,
    direction,
}: {
    active: boolean;
    direction: SortDirection;
}) {
    if (!active) {
        return <ArrowUpDown data-icon="inline-end" />;
    }

    return direction === 'ascending' ? (
        <ArrowUp data-icon="inline-end" />
    ) : (
        <ArrowDown data-icon="inline-end" />
    );
}

function RuleActions({ rule }: { rule: MerchantRule }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`Actions for ${rule.merchant}`}
                >
                    <MoreHorizontal data-icon="inline-start" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    <DropdownMenuItem>Edit rule</DropdownMenuItem>
                    <DropdownMenuItem>Duplicate rule</DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem variant="destructive">
                        Delete rule
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ruleScope(rule: MerchantRule) {
    return `${rule.transaction_kind ?? 'Any kind'} · ${rule.currency ?? 'Any currency'}`;
}

export function MerchantRulesTablePrototype({
    rules,
    categoryOptions,
}: {
    rules: MerchantRule[];
    categoryOptions: CategoryOption[];
}) {
    const [search, setSearch] = useState('');
    const [showEnabled, setShowEnabled] = useState(true);
    const [showDisabled, setShowDisabled] = useState(false);
    const [kindFilter, setKindFilter] = useState<KindFilter>('all');
    const [currencyFilter, setCurrencyFilter] = useState<CurrencyFilter>('all');
    const [sortKey, setSortKey] = useState<RuleSortKey>('category');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('ascending');
    const prototype = useMemo(
        () => prototypeRules(rules, categoryOptions),
        [categoryOptions, rules],
    );

    const rows = useMemo<RuleRow[]>(() => {
        const paths = new Map(
            categoryOptions.map((category) => [category.id, category.path]),
        );

        return prototype.rules.map((rule) => ({
            rule,
            categoryPath: paths.get(rule.category_id) ?? rule.category_name,
        }));
    }, [categoryOptions, prototype.rules]);

    const visibleRows = useMemo(() => {
        const query = normalizeSearch(search.trim());
        const collator = new Intl.Collator(undefined, {
            numeric: true,
            sensitivity: 'base',
        });

        return rows
            .filter(({ categoryPath, rule }) => {
                const matchesSearch =
                    query === '' ||
                    normalizeSearch(
                        `${rule.merchant} ${rule.merchant_key} ${categoryPath}`,
                    ).includes(query);
                const matchesStatus = rule.enabled ? showEnabled : showDisabled;
                const matchesKind =
                    kindFilter === 'all' ||
                    rule.transaction_kind === kindFilter;
                const matchesCurrency =
                    currencyFilter === 'all' ||
                    rule.currency === currencyFilter;

                return (
                    matchesSearch &&
                    matchesStatus &&
                    matchesKind &&
                    matchesCurrency
                );
            })
            .sort((left, right) => {
                let comparison = 0;

                if (sortKey === 'merchant') {
                    comparison = collator.compare(
                        left.rule.merchant,
                        right.rule.merchant,
                    );
                } else if (sortKey === 'category') {
                    comparison = collator.compare(
                        left.categoryPath,
                        right.categoryPath,
                    );
                } else if (sortKey === 'kind') {
                    comparison = collator.compare(
                        left.rule.transaction_kind ?? '',
                        right.rule.transaction_kind ?? '',
                    );
                } else if (sortKey === 'currency') {
                    comparison = collator.compare(
                        left.rule.currency ?? '',
                        right.rule.currency ?? '',
                    );
                } else {
                    comparison =
                        Number(right.rule.enabled) - Number(left.rule.enabled);
                }

                if (comparison === 0) {
                    comparison = collator.compare(
                        left.rule.merchant,
                        right.rule.merchant,
                    );
                }

                return sortDirection === 'ascending' ? comparison : -comparison;
            });
    }, [
        currencyFilter,
        kindFilter,
        rows,
        search,
        showDisabled,
        showEnabled,
        sortDirection,
        sortKey,
    ]);

    const activeFilterCount =
        Number(!showEnabled || showDisabled) +
        Number(kindFilter !== 'all') +
        Number(currencyFilter !== 'all');

    const changeSort = (key: RuleSortKey) => {
        if (sortKey === key) {
            setSortDirection((direction) =>
                direction === 'ascending' ? 'descending' : 'ascending',
            );

            return;
        }

        setSortKey(key);
        setSortDirection('ascending');
    };

    const sortableHeader = (
        key: RuleSortKey,
        label: string,
        className?: string,
    ) => (
        <TableHead
            className={className}
            aria-sort={sortKey === key ? sortDirection : 'none'}
        >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="-ml-3"
                onClick={() => changeSort(key)}
            >
                {label}
                <SortIcon active={sortKey === key} direction={sortDirection} />
            </Button>
        </TableHead>
    );

    return (
        <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Store className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Merchant rules
                        </h1>
                        {prototype.usesSamples && (
                            <Badge variant="secondary">Sample rules</Badge>
                        )}
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Find rules by merchant or category. Rules apply only to
                        future transactions.
                    </p>
                </div>
                <Button type="button" className="shrink-0">
                    <Plus data-icon="inline-start" />
                    New rule
                </Button>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="relative w-full sm:max-w-sm">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={search}
                        className="pl-9"
                        aria-label="Search merchant rules"
                        placeholder="Search merchants or categories"
                        onChange={(event) =>
                            setSearch(event.currentTarget.value)
                        }
                    />
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button type="button" variant="outline">
                            <SlidersHorizontal data-icon="inline-start" />
                            Filters
                            {activeFilterCount > 0 && ` (${activeFilterCount})`}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-56">
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>Status</DropdownMenuLabel>
                            <DropdownMenuCheckboxItem
                                checked={showEnabled}
                                onCheckedChange={(checked) =>
                                    setShowEnabled(checked === true)
                                }
                            >
                                Enabled
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuCheckboxItem
                                checked={showDisabled}
                                onCheckedChange={(checked) =>
                                    setShowDisabled(checked === true)
                                }
                            >
                                Disabled
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>
                                Transaction kind
                            </DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={kindFilter}
                                onValueChange={(value) =>
                                    setKindFilter(value as KindFilter)
                                }
                            >
                                <DropdownMenuRadioItem value="all">
                                    Any kind
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="spending">
                                    Spending
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="refund">
                                    Refund
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>Currency</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={currencyFilter}
                                onValueChange={(value) =>
                                    setCurrencyFilter(value as CurrencyFilter)
                                }
                            >
                                <DropdownMenuRadioItem value="all">
                                    Any currency
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="PEN">
                                    PEN
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="USD">
                                    USD
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
                {activeFilterCount > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            setShowEnabled(true);
                            setShowDisabled(false);
                            setKindFilter('all');
                            setCurrencyFilter('all');
                        }}
                    >
                        Clear filters
                    </Button>
                )}
            </div>

            <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                <p>
                    {visibleRows.length}{' '}
                    {visibleRows.length === 1 ? 'rule' : 'rules'}
                    {search.trim() !== '' && ` matching "${search.trim()}"`}
                </p>
                <p className="hidden sm:block">
                    Ordered by category by default.
                </p>
            </div>

            <div className="grid gap-2 sm:hidden">
                {visibleRows.map(({ categoryPath, rule }) => (
                    <article
                        key={rule.id}
                        className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 rounded-lg border p-3"
                    >
                        <div className="grid min-w-0 gap-1">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <h2 className="truncate font-medium">
                                    {rule.merchant}
                                </h2>
                                <Badge
                                    variant={
                                        rule.enabled ? 'outline' : 'secondary'
                                    }
                                >
                                    {rule.enabled ? 'Enabled' : 'Disabled'}
                                </Badge>
                            </div>
                            <p className="truncate text-sm">{categoryPath}</p>
                            <p className="text-xs text-muted-foreground capitalize">
                                {ruleScope(rule)}
                            </p>
                        </div>
                        <RuleActions rule={rule} />
                    </article>
                ))}
                {visibleRows.length === 0 && (
                    <p className="rounded-lg border px-4 py-12 text-center text-sm text-muted-foreground">
                        No merchant rules match this view.
                    </p>
                )}
            </div>

            <div className="hidden overflow-hidden rounded-lg border sm:block">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {sortableHeader('merchant', 'Merchant', 'pl-4')}
                            {sortableHeader('category', 'Category')}
                            {sortableHeader('kind', 'Kind')}
                            {sortableHeader('currency', 'Currency')}
                            {sortableHeader('status', 'Status')}
                            <TableHead className="w-32 text-right">
                                <span className="sr-only">Toggle status</span>
                            </TableHead>
                            <TableHead className="w-12 pr-4 text-right">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {visibleRows.map(({ categoryPath, rule }) => (
                            <TableRow key={rule.id}>
                                <TableCell className="min-w-52 py-2.5 pl-4 whitespace-normal">
                                    <div className="grid min-w-0 gap-0.5">
                                        <span className="font-medium wrap-break-word">
                                            {rule.merchant}
                                        </span>
                                        <span className="font-mono text-xs wrap-break-word text-muted-foreground">
                                            {rule.merchant_key}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="min-w-52 whitespace-normal">
                                    {categoryPath}
                                </TableCell>
                                <TableCell className="capitalize">
                                    {rule.transaction_kind ?? 'Any'}
                                </TableCell>
                                <TableCell>{rule.currency ?? 'Any'}</TableCell>
                                <TableCell>
                                    <Badge
                                        variant={
                                            rule.enabled
                                                ? 'outline'
                                                : 'secondary'
                                        }
                                    >
                                        {rule.enabled ? 'Enabled' : 'Disabled'}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                    >
                                        {rule.enabled ? 'Disable' : 'Enable'}
                                    </Button>
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    <RuleActions rule={rule} />
                                </TableCell>
                            </TableRow>
                        ))}
                        {visibleRows.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={7}
                                    className="h-32 text-center text-muted-foreground"
                                >
                                    No merchant rules match this view.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
