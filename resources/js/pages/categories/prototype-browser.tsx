import { MoreHorizontal, PencilLine, Plus, Search, Tags } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { CategoryItem, CategoryNode } from '@/types';

function searchable(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase();
}

export function CategoryBrowserPrototype({
    categories,
}: {
    categories: CategoryNode[];
}) {
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(
        categories[0]?.id ?? null,
    );
    const normalizedQuery = searchable(query.trim());
    const visibleCategories = useMemo(
        () =>
            categories.filter(
                (root) =>
                    searchable(root.name).includes(normalizedQuery) ||
                    root.children.some((child) =>
                        searchable(child.name).includes(normalizedQuery),
                    ),
            ),
        [categories, normalizedQuery],
    );
    const selected =
        visibleCategories.find((category) => category.id === selectedId) ??
        visibleCategories[0] ??
        null;
    const selectedRows: CategoryItem[] = selected
        ? [selected, ...selected.children]
        : [];

    return (
        <div className="flex flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div className="grid gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Categories
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Pick a group, then manage its categories in a focused
                        table.
                    </p>
                </div>
                <Button type="button">
                    <Plus data-icon="inline-start" /> New category
                </Button>
            </div>

            <div className="relative">
                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search the whole taxonomy"
                    aria-label="Search categories"
                    className="pl-9"
                />
            </div>

            <div className="grid min-h-[32rem] gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>Groups</CardTitle>
                        <CardDescription>
                            Ordered alphabetically
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-1">
                        {visibleCategories.map((category) => (
                            <Button
                                key={category.id}
                                type="button"
                                variant="ghost"
                                className={cn(
                                    'h-auto justify-between px-3 py-2.5',
                                    selected?.id === category.id &&
                                        'bg-accent text-accent-foreground',
                                )}
                                onClick={() => setSelectedId(category.id)}
                            >
                                <span className="min-w-0 truncate">
                                    {category.name}
                                </span>
                                <Badge variant="secondary">
                                    {category.children.length}
                                </Badge>
                            </Button>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="grid gap-1.5">
                            <CardTitle>
                                {selected?.name ?? 'No categories found'}
                            </CardTitle>
                            <CardDescription>
                                {selected
                                    ? `${selected.children.length} children · ${selectedRows.reduce((total, category) => total + category.transaction_count, 0)} transaction assignments`
                                    : 'Try a different search.'}
                            </CardDescription>
                        </div>
                        {selected && (
                            <Button type="button" variant="outline" size="sm">
                                <Plus data-icon="inline-start" /> Add child
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="p-0">
                        {selected && (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Level</TableHead>
                                        <TableHead className="text-right">
                                            Transactions
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Rules
                                        </TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {selectedRows.map((category) => (
                                        <TableRow key={category.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2 font-medium">
                                                    {category.parent_id ===
                                                    null ? (
                                                        <Tags className="size-4 text-muted-foreground" />
                                                    ) : (
                                                        <span className="ml-1 text-muted-foreground">
                                                            ↳
                                                        </span>
                                                    )}
                                                    {category.name}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {category.parent_id === null
                                                    ? 'Top level'
                                                    : 'Child'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {category.transaction_count}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                0
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Actions for ${category.name}`}
                                                >
                                                    {category.parent_id ===
                                                    null ? (
                                                        <MoreHorizontal />
                                                    ) : (
                                                        <PencilLine />
                                                    )}
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
