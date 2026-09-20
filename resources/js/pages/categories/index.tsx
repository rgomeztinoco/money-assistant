import { Form, Head, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    ArrowDown,
    ArrowUp,
    MoreHorizontal,
    PencilLine,
    Plus,
    Search,
    Tags,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import {
    destroy as unarchiveCategory,
    store as archiveCategory,
} from '@/actions/App/Http/Controllers/CategoryArchivalController';
import {
    store as createCategory,
    update as updateCategory,
} from '@/actions/App/Http/Controllers/CategoryController';
import { CategoryPicker } from '@/components/category-picker';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { index } from '@/routes/categories';
import type { CategoryItem, CategoryNode, CategoryOption } from '@/types';

type CategoryFilters = {
    search: string;
    archived: 'without' | 'with' | 'only';
    sort: 'name' | 'children' | 'transactions' | 'rules';
    direction: 'asc' | 'desc';
};

function rootsAsOptions(categories: CategoryNode[]): CategoryOption[] {
    return categories
        .filter((category) => category.archived_at === null)
        .map((category) => ({
            id: category.id,
            name: category.name,
            path: category.name,
            parent_id: null,
            parent_name: null,
        }));
}

function CategoryFields({
    idPrefix,
    roots,
    category,
    parentId,
}: {
    idPrefix: string;
    roots: CategoryOption[];
    category?: CategoryItem;
    parentId?: number | null;
}) {
    return (
        <FieldGroup>
            <Field>
                <FieldLabel htmlFor={`${idPrefix}-name`}>Name</FieldLabel>
                <Input
                    id={`${idPrefix}-name`}
                    name="name"
                    defaultValue={category?.name ?? ''}
                    maxLength={255}
                    required
                />
            </Field>
            <Field>
                <FieldLabel htmlFor={`${idPrefix}-parent`}>Parent</FieldLabel>
                <CategoryPicker
                    id={`${idPrefix}-parent`}
                    name="parent_id"
                    options={roots.filter((root) => root.id !== category?.id)}
                    defaultValue={(
                        parentId ??
                        category?.parent_id ??
                        ''
                    ).toString()}
                    emptyLabel="Top-level Category"
                    createTopLevelOnly
                />
                <FieldDescription>
                    Categories support at most two levels.
                </FieldDescription>
            </Field>
        </FieldGroup>
    );
}

function CategoryFormDialog({
    open,
    onOpenChange,
    roots,
    category,
    parentId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roots: CategoryOption[];
    category?: CategoryItem;
    parentId?: number | null;
}) {
    const editing = category !== undefined;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? `Edit ${category.name}`
                            : 'Create a Category'}
                    </DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'Renaming or moving this Category keeps its identity and updates historical reporting labels.'
                            : 'Add a top-level Category or place it under one active parent.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...(editing
                        ? updateCategory.form(category.id)
                        : createCategory.form())}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={!editing}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <div className="flex flex-col gap-4">
                            <CategoryFields
                                idPrefix={
                                    editing
                                        ? `category-${category.id}`
                                        : 'new-category'
                                }
                                roots={roots}
                                category={category}
                                parentId={parentId}
                            />
                            <InputError
                                message={errors.name ?? errors.parent_id}
                            />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {editing
                                        ? 'Save Category'
                                        : 'Create Category'}
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function SortButton({
    column,
    children,
    filters,
    updateFilters,
}: {
    column: CategoryFilters['sort'];
    children: React.ReactNode;
    filters: CategoryFilters;
    updateFilters: (updates: Partial<CategoryFilters>) => void;
}) {
    const active = filters.sort === column;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="-ml-3"
            onClick={() =>
                updateFilters({
                    sort: column,
                    direction:
                        active && filters.direction === 'asc' ? 'desc' : 'asc',
                })
            }
        >
            {children}
            {active &&
                (filters.direction === 'asc' ? <ArrowUp /> : <ArrowDown />)}
        </Button>
    );
}

export default function CategoriesIndex({
    categories,
    category_options: categoryOptions,
    filters,
}: {
    categories: CategoryNode[];
    category_options: CategoryOption[];
    filters: CategoryFilters;
}) {
    const [selectedId, setSelectedId] = useState('');
    const [search, setSearch] = useState(filters.search);
    const [createOpen, setCreateOpen] = useState(false);
    const [createParentId, setCreateParentId] = useState<number | null>(null);
    const [editing, setEditing] = useState<CategoryItem | null>(null);
    const [archiving, setArchiving] = useState<CategoryItem | null>(null);
    const [lifecycleProcessing, setLifecycleProcessing] = useState(false);
    const rootOptions = useMemo(
        () => categoryOptions.filter((category) => category.parent_id === null),
        [categoryOptions],
    );
    const browserOptions = useMemo(
        () => rootsAsOptions(categories),
        [categories],
    );
    const selectedRoot = categories.find(
        (category) => category.id.toString() === selectedId,
    );
    const rows: CategoryItem[] = selectedRoot
        ? [selectedRoot, ...selectedRoot.children]
        : categories.flatMap((category) => [category, ...category.children]);

    function updateFilters(updates: Partial<CategoryFilters>): void {
        router.get(
            index.url(),
            { ...filters, ...updates },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    }

    function submitSearch(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        setSelectedId('');
        updateFilters({ search });
    }

    function restore(category: CategoryItem): void {
        setLifecycleProcessing(true);
        router.delete(unarchiveCategory(category.id), {
            preserveScroll: true,
            onFinish: () => setLifecycleProcessing(false),
        });
    }

    function archive(): void {
        if (archiving === null) {
            return;
        }

        setLifecycleProcessing(true);
        router.post(
            archiveCategory(archiving.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedId('');
                    setArchiving(null);
                },
                onFinish: () => setLifecycleProcessing(false),
            },
        );
    }

    return (
        <>
            <Head title="Categories" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-2">
                            <Tags className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Categories
                            </h1>
                        </div>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Manage the two-level taxonomy used across current
                            and historical reporting. Uncategorized remains a
                            system state and is not listed here.
                        </p>
                    </div>
                    <Button
                        type="button"
                        onClick={() => {
                            setCreateParentId(null);
                            setCreateOpen(true);
                        }}
                    >
                        <Plus data-icon="inline-start" /> New Category
                    </Button>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row">
                    <form
                        className="flex min-w-0 flex-1 gap-2"
                        onSubmit={submitSearch}
                    >
                        <Input
                            type="search"
                            value={search}
                            aria-label="Search Categories"
                            placeholder="Search the whole taxonomy"
                            onChange={(event) =>
                                setSearch(event.currentTarget.value)
                            }
                        />
                        <Button type="submit" variant="outline">
                            <Search data-icon="inline-start" /> Search
                        </Button>
                    </form>
                    <NativeSelect
                        className="sm:w-52 sm:shrink-0"
                        aria-label="Archived Categories"
                        value={filters.archived}
                        onChange={(event) => {
                            setSelectedId('');
                            updateFilters({
                                archived: event.currentTarget
                                    .value as CategoryFilters['archived'],
                            });
                        }}
                        options={[
                            { value: 'without', label: 'Active Categories' },
                            { value: 'with', label: 'All Categories' },
                            { value: 'only', label: 'Archived Categories' },
                        ]}
                    />
                </div>

                <div className="lg:hidden">
                    <CategoryPicker
                        id="mobile-category-browser"
                        name="category_browser"
                        options={browserOptions}
                        value={selectedId}
                        onValueChange={setSelectedId}
                        emptyLabel="All Categories"
                        allowCreate={false}
                    />
                </div>

                <div className="grid min-h-[32rem] gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <Card className="hidden lg:block">
                        <CardHeader>
                            <CardTitle>Category browser</CardTitle>
                            <CardDescription>
                                Two levels, ordered by the current table sort
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-1">
                            <Button
                                data-test="category-browser-all"
                                type="button"
                                variant="ghost"
                                className={cn(
                                    'h-auto justify-between px-3 py-2.5',
                                    selectedId === '' &&
                                        'bg-accent text-accent-foreground',
                                )}
                                onClick={() => setSelectedId('')}
                            >
                                All Categories
                                <Badge variant="secondary">{rows.length}</Badge>
                            </Button>
                            {categories.map((category) => (
                                <Button
                                    key={category.id}
                                    data-test={`category-browser-${category.id}`}
                                    type="button"
                                    variant="ghost"
                                    className={cn(
                                        'h-auto justify-between px-3 py-2.5',
                                        selectedId === category.id.toString() &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                    onClick={() =>
                                        setSelectedId(category.id.toString())
                                    }
                                >
                                    <span className="truncate">
                                        {category.name}
                                    </span>
                                    <Badge variant="secondary">
                                        {category.child_count}
                                    </Badge>
                                </Button>
                            ))}
                        </CardContent>
                    </Card>

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle data-test="category-table-title">
                                {selectedRoot?.name ?? 'All Categories'}
                            </CardTitle>
                            <CardDescription>
                                {rows.length}{' '}
                                {rows.length === 1 ? 'Category' : 'Categories'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            {rows.length === 0 ? (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Tags />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            No Categories found
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            Change the search or archived
                                            filter, or create a Category.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                <SortButton
                                                    column="name"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Category
                                                </SortButton>
                                            </TableHead>
                                            <TableHead className="text-right">
                                                <SortButton
                                                    column="children"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Children
                                                </SortButton>
                                            </TableHead>
                                            <TableHead className="text-right">
                                                <SortButton
                                                    column="transactions"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Transactions
                                                </SortButton>
                                            </TableHead>
                                            <TableHead className="text-right">
                                                <SortButton
                                                    column="rules"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Rules
                                                </SortButton>
                                            </TableHead>
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((category) => {
                                            const parent = categories.find(
                                                (candidate) =>
                                                    candidate.id ===
                                                    category.parent_id,
                                            );

                                            return (
                                                <TableRow key={category.id}>
                                                    <TableCell>
                                                        <div className="flex min-w-48 items-center gap-2">
                                                            {category.parent_id ===
                                                            null ? (
                                                                <Tags className="text-muted-foreground" />
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    ↳
                                                                </span>
                                                            )}
                                                            <div className="flex min-w-0 flex-col gap-1">
                                                                <span className="font-medium">
                                                                    {selectedRoot ||
                                                                    category.parent_id ===
                                                                        null
                                                                        ? category.name
                                                                        : `${parent?.name ?? ''} > ${category.name}`}
                                                                </span>
                                                                {category.archived_at !==
                                                                    null && (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="w-fit"
                                                                    >
                                                                        Archived
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {category.child_count}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {
                                                            category.transaction_count
                                                        }
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {
                                                            category.active_merchant_rule_count
                                                        }
                                                    </TableCell>
                                                    <TableCell>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                asChild
                                                            >
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
                                                                    {category.parent_id ===
                                                                        null &&
                                                                        category.archived_at ===
                                                                            null && (
                                                                            <DropdownMenuItem
                                                                                onSelect={() => {
                                                                                    setCreateParentId(
                                                                                        category.id,
                                                                                    );
                                                                                    setCreateOpen(
                                                                                        true,
                                                                                    );
                                                                                }}
                                                                            >
                                                                                <Plus />{' '}
                                                                                Add
                                                                                subcategory
                                                                            </DropdownMenuItem>
                                                                        )}
                                                                    <DropdownMenuItem
                                                                        onSelect={() =>
                                                                            setEditing(
                                                                                category,
                                                                            )
                                                                        }
                                                                    >
                                                                        <PencilLine />{' '}
                                                                        Edit
                                                                    </DropdownMenuItem>
                                                                    {category.archived_at ===
                                                                    null ? (
                                                                        <DropdownMenuItem
                                                                            variant="destructive"
                                                                            onSelect={() =>
                                                                                setArchiving(
                                                                                    category,
                                                                                )
                                                                            }
                                                                        >
                                                                            <Archive />{' '}
                                                                            Archive
                                                                        </DropdownMenuItem>
                                                                    ) : (
                                                                        <DropdownMenuItem
                                                                            disabled={
                                                                                lifecycleProcessing
                                                                            }
                                                                            onSelect={() =>
                                                                                restore(
                                                                                    category,
                                                                                )
                                                                            }
                                                                        >
                                                                            <ArchiveRestore />{' '}
                                                                            Restore
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                </DropdownMenuGroup>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <CategoryFormDialog
                key={`create-${createParentId ?? 'root'}`}
                open={createOpen}
                onOpenChange={setCreateOpen}
                roots={rootOptions}
                parentId={createParentId}
            />
            {editing !== null && (
                <CategoryFormDialog
                    key={`edit-${editing.id}`}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                    roots={rootOptions}
                    category={editing}
                />
            )}
            <AlertDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
            >
                <AlertDialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Archive {archiving?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Historical assignments stay unchanged. This will
                            archive{' '}
                            {archiving?.archive_impact.active_child_count ?? 0}{' '}
                            active{' '}
                            {(archiving?.archive_impact.active_child_count ??
                                0) === 1
                                ? 'child'
                                : 'children'}{' '}
                            and disable{' '}
                            {archiving?.archive_impact
                                .active_merchant_rule_count ?? 0}{' '}
                            active Merchant{' '}
                            {(archiving?.archive_impact
                                .active_merchant_rule_count ?? 0) === 1
                                ? 'Rule'
                                : 'Rules'}
                            .
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {(archiving?.archive_impact.active_children.length ?? 0) >
                        0 && (
                        <div className="flex flex-col gap-1 text-sm">
                            <p className="font-medium">Children to archive</p>
                            <ul className="list-disc pl-5 text-muted-foreground">
                                {archiving?.archive_impact.active_children.map(
                                    (child) => (
                                        <li key={child.id}>{child.name}</li>
                                    ),
                                )}
                            </ul>
                        </div>
                    )}
                    {(archiving?.archive_impact.active_merchant_rules.length ??
                        0) > 0 && (
                        <div className="flex flex-col gap-1 text-sm">
                            <p className="font-medium">
                                Merchant Rules to disable
                            </p>
                            <ul className="list-disc pl-5 text-muted-foreground">
                                {archiving?.archive_impact.active_merchant_rules.map(
                                    (rule) => (
                                        <li key={rule.id}>
                                            {rule.merchant} (
                                            {rule.category_path})
                                        </li>
                                    ),
                                )}
                            </ul>
                        </div>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={lifecycleProcessing}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={lifecycleProcessing}
                            onClick={archive}
                        >
                            {lifecycleProcessing && <Spinner />}
                            Archive Category
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

CategoriesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Categories',
            href: index(),
        },
    ],
};
