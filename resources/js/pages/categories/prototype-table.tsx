import {
    Archive,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronRight,
    MoreHorizontal,
    Plus,
    Search,
    Tags,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
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
import type { CategoryItem, CategoryNode } from '@/types';

// Throwaway table-first category manager prototype for comparison on the existing Categories route.

type CategorySortKey =
    'category' | 'status' | 'children' | 'transactions' | 'rules';

type SortDirection = 'ascending' | 'descending';

type CategoryWithRuleCount = CategoryItem & {
    active_merchant_rule_count?: number;
    active_rule_count?: number;
    merchant_rule_count?: number;
};

type CategoryRow = {
    category: CategoryWithRuleCount;
    childCount: number;
    parentName: string | null;
    path: string;
    ruleCount: number | null;
};

function normalizeSearch(value: string) {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase();
}

function ruleCount(category: CategoryWithRuleCount) {
    return (
        category.active_merchant_rule_count ??
        category.active_rule_count ??
        category.merchant_rule_count ??
        null
    );
}

function categoryRows(categories: CategoryNode[]): CategoryRow[] {
    return categories.flatMap((root) => [
        {
            category: root,
            childCount: root.children.length,
            parentName: null,
            path: root.name,
            ruleCount: ruleCount(root),
        },
        ...root.children.map((child) => ({
            category: child,
            childCount: 0,
            parentName: root.name,
            path: `${root.name} / ${child.name}`,
            ruleCount: ruleCount(child),
        })),
    ]);
}

function CategoryActions({ row }: { row: CategoryRow }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`Actions for ${row.category.name}`}
                >
                    <MoreHorizontal data-icon="inline-start" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    {row.parentName === null && (
                        <DropdownMenuItem>
                            <Plus />
                            Add subcategory
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem>Rename or move</DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem>
                        <Archive />
                        {row.category.archived_at === null
                            ? 'Archive'
                            : 'Restore'}
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
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

function countLabel(count: number, singular: string) {
    return `${count} ${count === 1 ? singular : `${singular}s`}`;
}

export function CategoryTablePrototype({
    categories,
}: {
    categories: CategoryNode[];
}) {
    const [search, setSearch] = useState('');
    const [showArchived, setShowArchived] = useState(false);
    const [sortKey, setSortKey] = useState<CategorySortKey>('category');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('ascending');

    const allRows = useMemo(() => categoryRows(categories), [categories]);
    const archivedCount = allRows.filter(
        (row) => row.category.archived_at !== null,
    ).length;

    const visibleRows = useMemo(() => {
        const query = normalizeSearch(search.trim());
        const collator = new Intl.Collator(undefined, {
            numeric: true,
            sensitivity: 'base',
        });

        return allRows
            .filter(
                (row) =>
                    (showArchived || row.category.archived_at === null) &&
                    (query === '' || normalizeSearch(row.path).includes(query)),
            )
            .sort((left, right) => {
                let comparison = 0;

                if (sortKey === 'category') {
                    comparison = collator.compare(left.path, right.path);
                } else if (sortKey === 'status') {
                    comparison =
                        Number(left.category.archived_at !== null) -
                        Number(right.category.archived_at !== null);
                } else if (sortKey === 'children') {
                    comparison = left.childCount - right.childCount;
                } else if (sortKey === 'transactions') {
                    comparison =
                        left.category.transaction_count -
                        right.category.transaction_count;
                } else {
                    comparison =
                        (left.ruleCount ?? -1) - (right.ruleCount ?? -1);
                }

                return sortDirection === 'ascending' ? comparison : -comparison;
            });
    }, [allRows, search, showArchived, sortDirection, sortKey]);

    const changeSort = (key: CategorySortKey) => {
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
        key: CategorySortKey,
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
                    <div className="flex items-center gap-2">
                        <Tags className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Categories
                        </h1>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Search, order, and maintain the two-level taxonomy used
                        in reports and merchant rules.
                    </p>
                </div>
                <Button type="button" className="shrink-0">
                    <Plus data-icon="inline-start" />
                    New category
                </Button>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="relative w-full sm:max-w-sm">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={search}
                        className="pl-9"
                        aria-label="Search categories"
                        placeholder="Search categories"
                        onChange={(event) =>
                            setSearch(event.currentTarget.value)
                        }
                    />
                </div>
                {archivedCount > 0 && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setShowArchived((current) => !current)}
                    >
                        <Archive data-icon="inline-start" />
                        {showArchived
                            ? 'Hide archived'
                            : `Show archived (${archivedCount})`}
                    </Button>
                )}
            </div>

            <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                <p>
                    {countLabel(visibleRows.length, 'category')}
                    {search.trim() !== '' && ` matching "${search.trim()}"`}
                </p>
                <p className="hidden sm:block">
                    Children stay identified by their parent.
                </p>
            </div>

            <div className="grid gap-2 sm:hidden">
                {visibleRows.map((row) => (
                    <article
                        key={row.category.id}
                        className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 rounded-lg border p-3"
                    >
                        <div className="grid min-w-0 gap-1">
                            {row.parentName !== null && (
                                <span className="text-xs text-muted-foreground">
                                    {row.parentName}
                                </span>
                            )}
                            <div className="flex min-w-0 items-center gap-1.5">
                                {row.parentName !== null && (
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                )}
                                <h2 className="truncate font-medium">
                                    {row.category.name}
                                </h2>
                                {row.category.archived_at !== null && (
                                    <Badge variant="secondary">Archived</Badge>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {countLabel(row.childCount, 'child')} ·{' '}
                                {countLabel(
                                    row.category.transaction_count,
                                    'transaction',
                                )}{' '}
                                ·{' '}
                                {row.ruleCount === null
                                    ? 'Rule count unavailable'
                                    : countLabel(row.ruleCount, 'active rule')}
                            </p>
                        </div>
                        <CategoryActions row={row} />
                    </article>
                ))}
                {visibleRows.length === 0 && (
                    <p className="rounded-lg border px-4 py-12 text-center text-sm text-muted-foreground">
                        No categories match this view.
                    </p>
                )}
            </div>

            <div className="hidden overflow-hidden rounded-lg border sm:block">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {sortableHeader('category', 'Category', 'pl-4')}
                            {sortableHeader('status', 'Status')}
                            {sortableHeader(
                                'children',
                                'Children',
                                'text-right',
                            )}
                            {sortableHeader(
                                'transactions',
                                'Transactions',
                                'text-right',
                            )}
                            {sortableHeader(
                                'rules',
                                'Active rules',
                                'text-right',
                            )}
                            <TableHead className="w-12 pr-4 text-right">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {visibleRows.map((row) => (
                            <TableRow key={row.category.id}>
                                <TableCell className="min-w-64 py-2.5 pl-4 whitespace-normal">
                                    <div className="flex items-center gap-2">
                                        {row.parentName !== null && (
                                            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                        )}
                                        <div className="grid min-w-0 gap-0.5">
                                            <span className="font-medium wrap-break-word">
                                                {row.category.name}
                                            </span>
                                            {row.parentName !== null && (
                                                <span className="text-xs text-muted-foreground">
                                                    In {row.parentName}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant={
                                            row.category.archived_at === null
                                                ? 'outline'
                                                : 'secondary'
                                        }
                                    >
                                        {row.category.archived_at === null
                                            ? 'Active'
                                            : 'Archived'}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.childCount}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.category.transaction_count}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.ruleCount ?? 'N/A'}
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    <CategoryActions row={row} />
                                </TableCell>
                            </TableRow>
                        ))}
                        {visibleRows.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={6}
                                    className="h-32 text-center text-muted-foreground"
                                >
                                    No categories match this view.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
