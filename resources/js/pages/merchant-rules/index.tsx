import { Form, Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Filter,
    MoreHorizontal,
    PencilLine,
    Plus,
    Search,
    Store,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    destroy as deleteRule,
    store as createRule,
    update as updateRule,
} from '@/actions/App/Http/Controllers/MerchantRuleController';
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
import { Checkbox } from '@/components/ui/checkbox';
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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
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
import { index } from '@/routes/merchant_rules';
import type { CategoryOption, MerchantRule } from '@/types';

type MerchantRuleFilters = {
    search: string;
    category_id: number | null;
    status: 'all' | 'enabled' | 'disabled';
    kind: 'all' | 'any' | 'spending' | 'refund';
    currency: 'all' | 'any' | 'PEN' | 'USD';
    sort: 'category' | 'merchant' | 'kind' | 'currency' | 'status';
    direction: 'asc' | 'desc';
};

type CategoryGroup = {
    id: number;
    path: string;
    rule_count: number;
};

type RulePrefill = {
    transaction_id: number;
    merchant: string;
    merchant_key: string;
    transaction_kind: 'spending' | 'refund';
    currency: 'PEN' | 'USD';
};

function normalizeMerchant(merchant: string): string {
    return merchant
        .normalize('NFKC')
        .toLowerCase()
        .replace(/\p{P}+/gu, ' ')
        .trim()
        .replace(/\s+/gu, ' ');
}

function RuleDialog({
    open,
    onOpenChange,
    categoryOptions,
    rule,
    prefill,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categoryOptions: CategoryOption[];
    rule?: MerchantRule;
    prefill?: RulePrefill | null;
}) {
    const editing = rule !== undefined;
    const [merchant, setMerchant] = useState(
        rule?.merchant ?? prefill?.merchant ?? '',
    );
    const merchantKey = normalizeMerchant(merchant);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? `Edit ${rule.merchant}`
                            : 'Create a Merchant Rule'}
                    </DialogTitle>
                    <DialogDescription>
                        {prefill
                            ? 'Known values are prefilled from the Transaction. Review them and choose a Category.'
                            : 'The rule applies only to future Uncategorized Transactions.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...(editing
                        ? updateRule.form(rule.id)
                        : createRule.form())}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={!editing}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <div className="flex flex-col gap-4">
                            {prefill && (
                                <input
                                    type="hidden"
                                    name="source_transaction_id"
                                    value={prefill.transaction_id}
                                />
                            )}
                            <FieldGroup>
                                <Field
                                    data-invalid={errors.merchant !== undefined}
                                >
                                    <FieldLabel htmlFor="rule-merchant">
                                        Merchant
                                    </FieldLabel>
                                    <Input
                                        id="rule-merchant"
                                        name="merchant"
                                        value={merchant}
                                        maxLength={255}
                                        required
                                        aria-invalid={
                                            errors.merchant !== undefined
                                        }
                                        onChange={(event) =>
                                            setMerchant(
                                                event.currentTarget.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.merchant} />
                                </Field>
                                <Field>
                                    <FieldLabel>
                                        Normalized merchant key
                                    </FieldLabel>
                                    <p className="rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm break-words">
                                        {merchantKey || 'Enter a merchant'}
                                    </p>
                                </Field>
                                <Field
                                    data-invalid={
                                        errors.category_id !== undefined
                                    }
                                >
                                    <FieldLabel htmlFor="rule-category">
                                        Category
                                    </FieldLabel>
                                    <CategoryPicker
                                        id="rule-category"
                                        name="category_id"
                                        options={categoryOptions}
                                        defaultValue={
                                            rule?.category_id.toString() ?? ''
                                        }
                                        required
                                        allowEmpty={false}
                                    />
                                    <InputError message={errors.category_id} />
                                </Field>
                                <Field
                                    data-invalid={
                                        errors.transaction_kind !== undefined
                                    }
                                >
                                    <FieldLabel htmlFor="rule-kind">
                                        Transaction kind
                                    </FieldLabel>
                                    <NativeSelect
                                        id="rule-kind"
                                        name="transaction_kind"
                                        defaultValue={
                                            rule?.transaction_kind ??
                                            prefill?.transaction_kind ??
                                            ''
                                        }
                                        options={[
                                            { value: '', label: 'Any kind' },
                                            {
                                                value: 'spending',
                                                label: 'Spending',
                                            },
                                            {
                                                value: 'refund',
                                                label: 'Refund',
                                            },
                                        ]}
                                    />
                                    <InputError
                                        message={errors.transaction_kind}
                                    />
                                </Field>
                                <Field
                                    data-invalid={errors.currency !== undefined}
                                >
                                    <FieldLabel htmlFor="rule-currency">
                                        Currency
                                    </FieldLabel>
                                    <NativeSelect
                                        id="rule-currency"
                                        name="currency"
                                        defaultValue={
                                            rule?.currency ??
                                            prefill?.currency ??
                                            ''
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'Any currency',
                                            },
                                            { value: 'PEN', label: 'PEN' },
                                            { value: 'USD', label: 'USD' },
                                        ]}
                                    />
                                    <InputError message={errors.currency} />
                                </Field>
                                <Field
                                    data-invalid={errors.enabled !== undefined}
                                >
                                    <FieldLabel htmlFor="rule-enabled">
                                        Status
                                    </FieldLabel>
                                    <NativeSelect
                                        id="rule-enabled"
                                        name="enabled"
                                        defaultValue={
                                            rule?.enabled === false ? '0' : '1'
                                        }
                                        options={[
                                            { value: '1', label: 'Enabled' },
                                            { value: '0', label: 'Disabled' },
                                        ]}
                                    />
                                    <InputError message={errors.enabled} />
                                </Field>
                            </FieldGroup>
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
                                        ? 'Save Merchant Rule'
                                        : 'Create Merchant Rule'}
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
    filters,
    updateFilters,
    children,
}: {
    column: MerchantRuleFilters['sort'];
    filters: MerchantRuleFilters;
    updateFilters: (updates: Partial<MerchantRuleFilters>) => void;
    children: React.ReactNode;
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

export default function MerchantRulesIndex({
    rules,
    category_groups: categoryGroups,
    category_options: categoryOptions,
    filters,
    prefill,
}: {
    rules: MerchantRule[];
    category_groups: CategoryGroup[];
    category_options: CategoryOption[];
    filters: MerchantRuleFilters;
    prefill: RulePrefill | null;
}) {
    const [search, setSearch] = useState(filters.search);
    const [createOpen, setCreateOpen] = useState(prefill !== null);
    const [editing, setEditing] = useState<MerchantRule | null>(null);
    const [deleting, setDeleting] = useState<MerchantRule | null>(null);
    const [processingDelete, setProcessingDelete] = useState(false);
    const [statusErrors, setStatusErrors] = useState<Record<number, string>>(
        {},
    );
    const effectiveCategoryId =
        filters.search === '' ? filters.category_id : null;
    const activeFilterCount = [
        filters.status,
        filters.kind,
        filters.currency,
    ].filter((value) => value !== 'all').length;
    const selectedGroup = categoryGroups.find(
        (group) => group.id === effectiveCategoryId,
    );
    const mobileOptions: CategoryOption[] = categoryGroups.map((group) => ({
        id: group.id,
        name: group.path.split(' > ').at(-1) ?? group.path,
        path: group.path,
        parent_id: null,
        parent_name: null,
    }));

    function updateFilters(updates: Partial<MerchantRuleFilters>): void {
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
        updateFilters({ search });
    }

    function setEnabled(rule: MerchantRule, enabled: boolean): void {
        setStatusErrors((current) => ({ ...current, [rule.id]: '' }));
        router.patch(
            updateRule(rule.id),
            {
                merchant: rule.merchant,
                category_id: rule.category_id,
                transaction_kind: rule.transaction_kind,
                currency: rule.currency,
                enabled,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setStatusErrors((current) => ({
                        ...current,
                        [rule.id]:
                            errors.enabled ??
                            errors.merchant ??
                            'The status could not be changed.',
                    })),
            },
        );
    }

    function confirmDelete(): void {
        if (deleting === null) {
            return;
        }

        setProcessingDelete(true);
        router.delete(deleteRule(deleting.id), {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
            onFinish: () => setProcessingDelete(false),
        });
    }

    return (
        <>
            <Head title="Merchant Rules" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-2">
                            <Store className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Merchant Rules
                            </h1>
                        </div>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Categorize future Transactions by an exact
                            normalized merchant match. Existing Transactions
                            never change.
                        </p>
                    </div>
                    <Button type="button" onClick={() => setCreateOpen(true)}>
                        <Plus data-icon="inline-start" /> New Merchant Rule
                    </Button>
                </div>

                <div className="flex gap-2">
                    <form
                        className="flex min-w-0 flex-1 gap-2"
                        onSubmit={submitSearch}
                    >
                        <Input
                            type="search"
                            value={search}
                            aria-label="Search Merchant Rules"
                            placeholder="Search rules and Categories"
                            onChange={(event) =>
                                setSearch(event.currentTarget.value)
                            }
                        />
                        <Button type="submit" variant="outline">
                            <Search data-icon="inline-start" /> Search
                        </Button>
                    </form>
                    <Popover>
                        <PopoverTrigger
                            render={
                                <Button
                                    data-test="rule-filters-trigger"
                                    type="button"
                                    variant="outline"
                                />
                            }
                        >
                            <Filter data-icon="inline-start" /> Filters
                            {activeFilterCount > 0 && (
                                <Badge variant="secondary">
                                    {activeFilterCount}
                                </Badge>
                            )}
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-72">
                            <PopoverHeader>
                                <PopoverTitle>Rule filters</PopoverTitle>
                                <PopoverDescription>
                                    Narrow the current rule table.
                                </PopoverDescription>
                            </PopoverHeader>
                            <FieldGroup className="gap-4">
                                <Field>
                                    <FieldLabel htmlFor="status-filter">
                                        Status
                                    </FieldLabel>
                                    <NativeSelect
                                        id="status-filter"
                                        value={filters.status}
                                        onChange={(event) =>
                                            updateFilters({
                                                status: event.currentTarget
                                                    .value as MerchantRuleFilters['status'],
                                            })
                                        }
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All statuses',
                                            },
                                            {
                                                value: 'enabled',
                                                label: 'Enabled',
                                            },
                                            {
                                                value: 'disabled',
                                                label: 'Disabled',
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="kind-filter">
                                        Transaction kind
                                    </FieldLabel>
                                    <NativeSelect
                                        id="kind-filter"
                                        value={filters.kind}
                                        onChange={(event) =>
                                            updateFilters({
                                                kind: event.currentTarget
                                                    .value as MerchantRuleFilters['kind'],
                                            })
                                        }
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All kinds',
                                            },
                                            {
                                                value: 'any',
                                                label: 'Any-kind rules',
                                            },
                                            {
                                                value: 'spending',
                                                label: 'Spending',
                                            },
                                            {
                                                value: 'refund',
                                                label: 'Refund',
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="currency-filter">
                                        Currency
                                    </FieldLabel>
                                    <NativeSelect
                                        id="currency-filter"
                                        value={filters.currency}
                                        onChange={(event) =>
                                            updateFilters({
                                                currency: event.currentTarget
                                                    .value as MerchantRuleFilters['currency'],
                                            })
                                        }
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All currencies',
                                            },
                                            {
                                                value: 'any',
                                                label: 'Any-currency rules',
                                            },
                                            { value: 'PEN', label: 'PEN' },
                                            { value: 'USD', label: 'USD' },
                                        ]}
                                    />
                                </Field>
                            </FieldGroup>
                        </PopoverContent>
                    </Popover>
                </div>

                <div className="lg:hidden">
                    <CategoryPicker
                        id="mobile-rule-category"
                        name="rule_category"
                        options={mobileOptions}
                        value={effectiveCategoryId?.toString() ?? ''}
                        onValueChange={(value) =>
                            updateFilters({
                                category_id:
                                    value === '' ? null : Number(value),
                            })
                        }
                        emptyLabel="All Rules"
                        allowCreate={false}
                    />
                </div>

                <div className="grid min-h-[32rem] gap-4 lg:grid-cols-[19rem_minmax(0,1fr)]">
                    <Card className="hidden lg:flex">
                        <CardHeader>
                            <CardTitle>Categories</CardTitle>
                            <CardDescription>
                                Only Categories with rules
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-1">
                            <Button
                                data-test="rule-browser-all"
                                type="button"
                                variant="ghost"
                                className={cn(
                                    'h-auto justify-between px-3 py-2.5',
                                    effectiveCategoryId === null &&
                                        'bg-accent text-accent-foreground',
                                )}
                                onClick={() =>
                                    updateFilters({ category_id: null })
                                }
                            >
                                All Rules
                                <Badge variant="secondary">
                                    {categoryGroups.reduce(
                                        (total, group) =>
                                            total + group.rule_count,
                                        0,
                                    )}
                                </Badge>
                            </Button>
                            {categoryGroups.map((group) => (
                                <Button
                                    key={group.id}
                                    data-test={`rule-browser-${group.id}`}
                                    type="button"
                                    variant="ghost"
                                    className={cn(
                                        'h-auto justify-between px-3 py-2.5 text-left whitespace-normal',
                                        effectiveCategoryId === group.id &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                    onClick={() =>
                                        updateFilters({ category_id: group.id })
                                    }
                                >
                                    <span>{group.path}</span>
                                    <Badge variant="secondary">
                                        {group.rule_count}
                                    </Badge>
                                </Button>
                            ))}
                        </CardContent>
                    </Card>

                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle data-test="rule-table-title">
                                {selectedGroup?.path ?? 'All Rules'}
                            </CardTitle>
                            <CardDescription>
                                {rules.length}{' '}
                                {rules.length === 1 ? 'rule' : 'rules'}
                                {filters.search !== '' &&
                                    ' across all Categories'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {rules.length === 0 ? (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Store />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            No Merchant Rules found
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            Change the search or filters, or
                                            create a rule manually.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                <SortButton
                                                    column="merchant"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Merchant
                                                </SortButton>
                                            </TableHead>
                                            <TableHead>
                                                <SortButton
                                                    column="category"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Category
                                                </SortButton>
                                            </TableHead>
                                            <TableHead>
                                                <SortButton
                                                    column="kind"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Kind
                                                </SortButton>
                                            </TableHead>
                                            <TableHead>
                                                <SortButton
                                                    column="currency"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Currency
                                                </SortButton>
                                            </TableHead>
                                            <TableHead>
                                                <SortButton
                                                    column="status"
                                                    filters={filters}
                                                    updateFilters={
                                                        updateFilters
                                                    }
                                                >
                                                    Enabled
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
                                        {rules.map((rule) => (
                                            <TableRow key={rule.id}>
                                                <TableCell className="font-medium">
                                                    {rule.merchant}
                                                </TableCell>
                                                <TableCell>
                                                    {rule.category_path}
                                                </TableCell>
                                                <TableCell>
                                                    {rule.transaction_kind ??
                                                        'Any'}
                                                </TableCell>
                                                <TableCell>
                                                    {rule.currency ?? 'Any'}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col gap-1">
                                                        <Checkbox
                                                            checked={
                                                                rule.enabled
                                                            }
                                                            aria-label={`Enable ${rule.merchant}`}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                setEnabled(
                                                                    rule,
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                statusErrors[
                                                                    rule.id
                                                                ]
                                                            }
                                                        />
                                                    </div>
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
                                                                aria-label={`Actions for ${rule.merchant}`}
                                                            >
                                                                <MoreHorizontal />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuGroup>
                                                                <DropdownMenuItem
                                                                    onSelect={() =>
                                                                        setEditing(
                                                                            rule,
                                                                        )
                                                                    }
                                                                >
                                                                    <PencilLine />{' '}
                                                                    Edit
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onSelect={() =>
                                                                        setDeleting(
                                                                            rule,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 />{' '}
                                                                    Delete
                                                                </DropdownMenuItem>
                                                            </DropdownMenuGroup>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
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

            <RuleDialog
                key={`create-${prefill?.transaction_id ?? 'manual'}`}
                open={createOpen}
                onOpenChange={setCreateOpen}
                categoryOptions={categoryOptions}
                prefill={prefill}
            />
            {editing !== null && (
                <RuleDialog
                    key={`edit-${editing.id}`}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                    categoryOptions={categoryOptions}
                    rule={editing}
                />
            )}
            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {deleting?.merchant}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This removes the rule. Existing Category assignments
                            remain unchanged.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={processingDelete}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={processingDelete}
                            onClick={confirmDelete}
                        >
                            {processingDelete && <Spinner />}
                            Delete Merchant Rule
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

MerchantRulesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Merchant Rules',
            href: index(),
        },
    ],
};
