import { Form, Head } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    ChevronRight,
    PencilLine,
    Plus,
    Tags,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    destroy as deleteCategory,
    store as createCategory,
    update as updateCategory,
} from '@/actions/App/Http/Controllers/CategoryController';
import {
    destroy as reactivateCategory,
    store as retireCategory,
} from '@/actions/App/Http/Controllers/CategoryRetirementController';
import { default as restoreCategory } from '@/actions/App/Http/Controllers/CategoryTrashRestorationController';
import InputError from '@/components/input-error';
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
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/categories';
import type { CategoryItem, CategoryNode } from '@/types';

function CategoryFields({
    idPrefix,
    roots,
    category,
}: {
    idPrefix: string;
    roots: CategoryNode[];
    category?: CategoryItem;
}) {
    const [examples, setExamples] = useState(
        category?.examples.length ? category.examples : [''],
    );

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-name`}>Name</Label>
                <Input
                    id={`${idPrefix}-name`}
                    name="name"
                    defaultValue={category?.name ?? ''}
                    maxLength={255}
                    required
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-parent`}>Parent</Label>
                <NativeSelect
                    id={`${idPrefix}-parent`}
                    name="parent_id"
                    defaultValue={category?.parent_id?.toString() ?? ''}
                    options={[
                        { value: '', label: 'Top-level Category' },
                        ...roots
                            .filter(
                                (root) =>
                                    root.retired_at === null &&
                                    root.id !== category?.id,
                            )
                            .map((root) => ({
                                value: root.id.toString(),
                                label: root.name,
                            })),
                    ]}
                />
                <p className="text-xs text-muted-foreground">
                    Categories support at most two levels.
                </p>
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-description`}>
                    AI guidance description
                </Label>
                <Input
                    id={`${idPrefix}-description`}
                    name="description"
                    defaultValue={category?.description ?? ''}
                    maxLength={2000}
                    placeholder="Optional guidance for classification"
                />
            </div>
            <fieldset className="grid gap-2">
                <legend className="text-sm font-medium">Examples</legend>
                {examples.map((example, exampleIndex) => (
                    <div key={exampleIndex} className="flex gap-2">
                        <Label
                            htmlFor={`${idPrefix}-example-${exampleIndex}`}
                            className="sr-only"
                        >
                            Example {exampleIndex + 1}
                        </Label>
                        <Input
                            id={`${idPrefix}-example-${exampleIndex}`}
                            name="examples[]"
                            defaultValue={example}
                            maxLength={100}
                            aria-label={`Example ${exampleIndex + 1}`}
                            placeholder="Optional example"
                        />
                        {examples.length > 1 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={`Remove example ${exampleIndex + 1}`}
                                onClick={() =>
                                    setExamples((currentExamples) =>
                                        currentExamples.filter(
                                            (_, index) =>
                                                index !== exampleIndex,
                                        ),
                                    )
                                }
                            >
                                <X />
                            </Button>
                        )}
                    </div>
                ))}
                {examples.length < 20 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        onClick={() =>
                            setExamples((currentExamples) => [
                                ...currentExamples,
                                '',
                            ])
                        }
                    >
                        <Plus /> Add example
                    </Button>
                )}
            </fieldset>
        </>
    );
}

function EditCategoryDialog({
    category,
    roots,
}: {
    category: CategoryItem;
    roots: CategoryNode[];
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <PencilLine /> Edit
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Edit {category.name}</DialogTitle>
                    <DialogDescription>
                        Renaming or moving this Category keeps its identity and
                        updates historical reporting labels.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...updateCategory.form(category.id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="expected_revision"
                                value={category.revision}
                            />
                            <CategoryFields
                                idPrefix={`category-${category.id}`}
                                roots={roots}
                                category={category}
                            />
                            <InputError
                                message={
                                    errors.name ??
                                    errors.parent_id ??
                                    errors.description ??
                                    errors.examples ??
                                    errors.expected_revision
                                }
                            />
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Save Category
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function LifecycleActions({
    category,
    hasChildren,
}: {
    category: CategoryItem;
    hasChildren: boolean;
}) {
    const isRetired = category.retired_at !== null;
    const lifecycleRoute = isRetired
        ? reactivateCategory.form(category.id)
        : retireCategory.form(category.id);

    return (
        <div className="flex flex-wrap gap-2">
            <Form {...lifecycleRoute} options={{ preserveScroll: true }}>
                {({ errors, processing }) => (
                    <div className="grid gap-1">
                        <input
                            type="hidden"
                            name="expected_revision"
                            value={category.revision}
                        />
                        <Button
                            type="submit"
                            variant="secondary"
                            size="sm"
                            disabled={processing}
                        >
                            {processing ? (
                                <Spinner />
                            ) : isRetired ? (
                                <ArchiveRestore />
                            ) : (
                                <Archive />
                            )}
                            {isRetired ? 'Reactivate' : 'Retire'}
                        </Button>
                        <InputError
                            message={
                                errors.category ?? errors.expected_revision
                            }
                        />
                    </div>
                )}
            </Form>
            {category.transaction_count === 0 && !hasChildren && (
                <Form
                    {...deleteCategory.form(category.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <div className="grid gap-1">
                            <input
                                type="hidden"
                                name="expected_revision"
                                value={category.revision}
                            />
                            <Button
                                type="submit"
                                variant="destructive"
                                size="sm"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <Trash2 />}
                                Delete
                            </Button>
                            <InputError
                                message={
                                    errors.category ?? errors.expected_revision
                                }
                            />
                        </div>
                    )}
                </Form>
            )}
        </div>
    );
}

function CategorySummary({ category }: { category: CategoryItem }) {
    return (
        <div className="grid min-w-0 gap-1">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-medium">{category.name}</h3>
                <Badge
                    variant={
                        category.retired_at === null ? 'outline' : 'secondary'
                    }
                >
                    {category.retired_at === null ? 'Active' : 'Retired'}
                </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
                {category.description ?? 'No AI guidance description.'}
            </p>
            {category.examples.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    Examples: {category.examples.join(', ')}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                {category.transaction_count}{' '}
                {category.transaction_count === 1
                    ? 'Transaction assignment'
                    : 'Transaction assignments'}{' '}
                | Revision {category.revision}
            </p>
        </div>
    );
}

export default function CategoriesIndex({
    categories,
    trashed_categories: trashedCategories,
}: {
    categories: CategoryNode[];
    trashed_categories: Array<{
        deletion_id: string;
        name: string;
        purge_after: string;
    }>;
}) {
    return (
        <>
            <Head title="Categories" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <Tags className="size-5 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Categories
                        </h1>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Manage the two-level taxonomy used across current and
                        historical reporting. Uncategorized remains a system
                        state and is not listed here.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Create a Category</CardTitle>
                        <CardDescription>
                            Add a top-level Category or place it under one
                            active parent.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...createCategory.form()}
                            resetOnSuccess
                            className="grid gap-4 md:grid-cols-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <CategoryFields
                                        idPrefix="new-category"
                                        roots={categories}
                                    />
                                    <InputError
                                        message={
                                            errors.name ??
                                            errors.parent_id ??
                                            errors.description ??
                                            errors.examples
                                        }
                                    />
                                    <div className="md:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Plus />
                                            )}
                                            Create Category
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                {trashedCategories.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ArchiveRestore className="size-5" /> Category
                                trash
                            </CardTitle>
                            <CardDescription>
                                Restore deleted Categories before their 30-day
                                recovery window expires.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {trashedCategories.map((category) => (
                                <div
                                    key={category.deletion_id}
                                    className="flex flex-col justify-between gap-3 rounded-lg border p-3 sm:flex-row sm:items-center"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {category.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Purges after{' '}
                                            {new Date(
                                                category.purge_after,
                                            ).toLocaleString()}
                                        </p>
                                    </div>
                                    <Form
                                        {...restoreCategory.form(
                                            category.deletion_id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                                aria-label={`Restore ${category.name}`}
                                            >
                                                {processing ? (
                                                    <Spinner />
                                                ) : (
                                                    <ArchiveRestore />
                                                )}
                                                Restore
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 xl:grid-cols-2">
                    {categories.map((root) => (
                        <Card key={root.id} className="gap-0 overflow-hidden">
                            <CardContent className="grid gap-4 p-4 md:p-5">
                                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                    <CategorySummary category={root} />
                                    <div className="flex shrink-0 flex-wrap gap-2">
                                        <EditCategoryDialog
                                            category={root}
                                            roots={categories}
                                        />
                                        <LifecycleActions
                                            category={root}
                                            hasChildren={
                                                root.children.length > 0
                                            }
                                        />
                                    </div>
                                </div>

                                {root.children.length > 0 && (
                                    <div className="grid gap-2 border-l pl-3 md:pl-5">
                                        {root.children.map((child) => (
                                            <div
                                                key={child.id}
                                                className="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                                            >
                                                <div className="flex min-w-0 gap-2">
                                                    <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                                    <CategorySummary
                                                        category={child}
                                                    />
                                                </div>
                                                <div className="flex flex-wrap gap-2 sm:justify-end">
                                                    <EditCategoryDialog
                                                        category={child}
                                                        roots={categories}
                                                    />
                                                    <LifecycleActions
                                                        category={child}
                                                        hasChildren={false}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
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
