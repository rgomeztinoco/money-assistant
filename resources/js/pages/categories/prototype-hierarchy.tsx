import {
    Archive,
    ChevronDown,
    ChevronRight,
    MoreHorizontal,
    PencilLine,
    Plus,
    Search,
} from 'lucide-react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import type { CategoryItem, CategoryNode } from '@/types';

type CategoryStatus = 'active' | 'archived' | 'all';
type CategorySort = 'name' | 'usage';

function searchable(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase();
}

function matchesStatus(
    category: CategoryItem,
    status: CategoryStatus,
): boolean {
    return (
        status === 'all' ||
        (status === 'active' && category.archived_at === null) ||
        (status === 'archived' && category.archived_at !== null)
    );
}

function CategoryActions({ category }: { category: CategoryItem }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`Actions for ${category.name}`}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    {category.parent_id === null && (
                        <DropdownMenuItem>
                            <Plus /> Add subcategory
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem>
                        <PencilLine /> Rename or move
                    </DropdownMenuItem>
                    <DropdownMenuItem variant="destructive">
                        <Archive /> Archive
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function CategoryMetadata({ category }: { category: CategoryItem }) {
    return (
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span>{category.transaction_count} transactions</span>
            <span>0 active rules</span>
            {category.archived_at !== null && (
                <Badge variant="secondary">Archived</Badge>
            )}
        </div>
    );
}

export function CategoryHierarchyPrototype({
    categories,
}: {
    categories: CategoryNode[];
}) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<CategoryStatus>('active');
    const [sort, setSort] = useState<CategorySort>('name');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(
        () => new Set(categories.map((category) => category.id)),
    );
    const normalizedQuery = searchable(query.trim());

    const visibleCategories = useMemo(() => {
        const result = categories
            .map((root) => {
                const rootMatches =
                    matchesStatus(root, status) &&
                    searchable(root.name).includes(normalizedQuery);
                const children = root.children.filter(
                    (child) =>
                        matchesStatus(child, status) &&
                        searchable(child.name).includes(normalizedQuery),
                );

                if (normalizedQuery === '') {
                    return {
                        ...root,
                        children: root.children.filter((child) =>
                            matchesStatus(child, status),
                        ),
                    };
                }

                if (!rootMatches && children.length === 0) {
                    return null;
                }

                return {
                    ...root,
                    children: rootMatches
                        ? root.children.filter((child) =>
                              matchesStatus(child, status),
                          )
                        : children,
                };
            })
            .filter((category): category is CategoryNode => category !== null);

        return result.sort((first, second) =>
            sort === 'usage'
                ? second.transaction_count - first.transaction_count
                : first.name.localeCompare(second.name),
        );
    }, [categories, normalizedQuery, sort, status]);

    function toggleExpanded(categoryId: number) {
        setExpandedIds((current) => {
            const next = new Set(current);

            if (next.has(categoryId)) {
                next.delete(categoryId);
            } else {
                next.add(categoryId);
            }

            return next;
        });
    }

    return (
        <div className="flex flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div className="grid gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Categories
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Browse your taxonomy, see where it is used, and add new
                        categories in context.
                    </p>
                </div>
                <Button type="button">
                    <Plus data-icon="inline-start" /> New category
                </Button>
            </div>

            <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div className="relative min-w-0 flex-1">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search categories"
                        aria-label="Search categories"
                        className="pl-9"
                    />
                </div>
                <div className="grid grid-cols-2 gap-2 sm:flex">
                    <NativeSelect
                        aria-label="Category status"
                        value={status}
                        onChange={(event) =>
                            setStatus(event.target.value as CategoryStatus)
                        }
                        className="sm:w-36"
                        options={[
                            { value: 'active', label: 'Active' },
                            { value: 'archived', label: 'Archived' },
                            { value: 'all', label: 'All statuses' },
                        ]}
                    />
                    <NativeSelect
                        aria-label="Sort categories"
                        value={sort}
                        onChange={(event) =>
                            setSort(event.target.value as CategorySort)
                        }
                        className="sm:w-44"
                        options={[
                            { value: 'name', label: 'Name A–Z' },
                            { value: 'usage', label: 'Most used' },
                        ]}
                    />
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Category structure</CardTitle>
                    <CardDescription>
                        {visibleCategories.length} top-level categories. Child
                        counts stay visible when a group is collapsed.
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="divide-y border-t">
                        {visibleCategories.map((root) => {
                            const isExpanded =
                                normalizedQuery !== '' ||
                                expandedIds.has(root.id);

                            return (
                                <section key={root.id}>
                                    <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 px-3 py-2.5 sm:px-4">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`${isExpanded ? 'Collapse' : 'Expand'} ${root.name}`}
                                            onClick={() =>
                                                toggleExpanded(root.id)
                                            }
                                        >
                                            {isExpanded ? (
                                                <ChevronDown />
                                            ) : (
                                                <ChevronRight />
                                            )}
                                        </Button>
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h2 className="truncate font-medium">
                                                    {root.name}
                                                </h2>
                                                <Badge variant="outline">
                                                    {root.children.length}{' '}
                                                    {root.children.length === 1
                                                        ? 'child'
                                                        : 'children'}
                                                </Badge>
                                            </div>
                                            <CategoryMetadata category={root} />
                                        </div>
                                        <CategoryActions category={root} />
                                    </div>

                                    {isExpanded &&
                                        root.children.map((child) => (
                                            <div
                                                key={child.id}
                                                className="grid grid-cols-[2.25rem_minmax(0,1fr)_auto] items-center gap-2 bg-muted/20 px-3 py-2.5 sm:px-4"
                                            >
                                                <div className="flex justify-end">
                                                    <ChevronRight className="size-4 text-muted-foreground" />
                                                </div>
                                                <div className="min-w-0">
                                                    <h3 className="truncate text-sm font-medium">
                                                        {child.name}
                                                    </h3>
                                                    <CategoryMetadata
                                                        category={child}
                                                    />
                                                </div>
                                                <CategoryActions
                                                    category={child}
                                                />
                                            </div>
                                        ))}
                                </section>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
