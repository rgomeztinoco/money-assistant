import {
    Filter,
    MoreHorizontal,
    PencilLine,
    Plus,
    Search,
    Trash2,
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { CategoryOption, MerchantRule } from '@/types';
import { categoryPath, prototypeRules } from './prototype-data';

type StatusFilter = 'all' | 'enabled' | 'disabled';

function searchable(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase();
}

function RuleActions({ rule }: { rule: MerchantRule }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
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
                    <DropdownMenuItem>
                        <PencilLine /> Edit rule
                    </DropdownMenuItem>
                    <DropdownMenuItem variant="destructive">
                        <Trash2 /> Delete rule
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function MerchantRulesGroupedPrototype({
    rules,
    categoryOptions,
}: {
    rules: MerchantRule[];
    categoryOptions: CategoryOption[];
}) {
    const prototype = useMemo(
        () => prototypeRules(rules, categoryOptions),
        [categoryOptions, rules],
    );
    const [query, setQuery] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [kind, setKind] = useState('');
    const [currency, setCurrency] = useState('');
    const normalizedQuery = searchable(query.trim());
    const activeFilterCount =
        [categoryId, kind, currency].filter(Boolean).length +
        (status === 'all' ? 0 : 1);

    const groups = useMemo(() => {
        const matchingRules = prototype.rules.filter((rule) => {
            const path = categoryPath(rule, categoryOptions);
            const matchesQuery = searchable(
                `${rule.merchant} ${rule.merchant_key} ${path}`,
            ).includes(normalizedQuery);

            return (
                matchesQuery &&
                (categoryId === '' ||
                    rule.category_id.toString() === categoryId) &&
                (status === 'all' ||
                    (status === 'enabled' && rule.enabled) ||
                    (status === 'disabled' && !rule.enabled)) &&
                (kind === '' || rule.transaction_kind === kind) &&
                (currency === '' || rule.currency === currency)
            );
        });

        return Array.from(
            matchingRules.reduce((grouped, rule) => {
                const path = categoryPath(rule, categoryOptions);
                const topLevel = path.split(' > ')[0];
                const existing = grouped.get(topLevel) ?? [];
                existing.push(rule);
                grouped.set(topLevel, existing);

                return grouped;
            }, new Map<string, MerchantRule[]>()),
        ).sort(([first], [second]) => first.localeCompare(second));
    }, [
        categoryId,
        categoryOptions,
        currency,
        kind,
        normalizedQuery,
        prototype.rules,
        status,
    ]);

    return (
        <div className="flex flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Merchant rules
                        </h1>
                        {prototype.usesSamples && (
                            <Badge variant="secondary">Sample rules</Badge>
                        )}
                    </div>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Rules are grouped by category so related automation is
                        easy to scan.
                    </p>
                </div>
                <Button type="button">
                    <Plus data-icon="inline-start" /> New rule
                </Button>
            </div>

            <div className="flex gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search merchants or categories"
                        aria-label="Search merchant rules"
                        className="pl-9"
                    />
                </div>
                <Popover>
                    <PopoverTrigger
                        render={<Button type="button" variant="outline" />}
                    >
                        <Filter data-icon="inline-start" /> Filters
                        {activeFilterCount > 0 && (
                            <Badge variant="secondary">
                                {activeFilterCount}
                            </Badge>
                        )}
                    </PopoverTrigger>
                    <PopoverContent align="end" className="w-80">
                        <PopoverHeader>
                            <PopoverTitle>Filter rules</PopoverTitle>
                            <PopoverDescription>
                                Keep secondary controls out of the main toolbar.
                            </PopoverDescription>
                        </PopoverHeader>
                        <div className="grid gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="grouped-category-filter">
                                    Category
                                </Label>
                                <NativeSelect
                                    id="grouped-category-filter"
                                    value={categoryId}
                                    onChange={(event) =>
                                        setCategoryId(event.target.value)
                                    }
                                    options={[
                                        {
                                            value: '',
                                            label: 'All categories',
                                        },
                                        ...categoryOptions.map((category) => ({
                                            value: category.id.toString(),
                                            label: category.path,
                                        })),
                                    ]}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="grouped-status-filter">
                                        Status
                                    </Label>
                                    <NativeSelect
                                        id="grouped-status-filter"
                                        value={status}
                                        onChange={(event) =>
                                            setStatus(
                                                event.target
                                                    .value as StatusFilter,
                                            )
                                        }
                                        options={[
                                            { value: 'all', label: 'All' },
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
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="grouped-kind-filter">
                                        Kind
                                    </Label>
                                    <NativeSelect
                                        id="grouped-kind-filter"
                                        value={kind}
                                        onChange={(event) =>
                                            setKind(event.target.value)
                                        }
                                        options={[
                                            { value: '', label: 'Any' },
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
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="grouped-currency-filter">
                                    Currency
                                </Label>
                                <NativeSelect
                                    id="grouped-currency-filter"
                                    value={currency}
                                    onChange={(event) =>
                                        setCurrency(event.target.value)
                                    }
                                    options={[
                                        { value: '', label: 'Any currency' },
                                        { value: 'PEN', label: 'PEN' },
                                        { value: 'USD', label: 'USD' },
                                    ]}
                                />
                            </div>
                        </div>
                    </PopoverContent>
                </Popover>
            </div>

            <div className="grid gap-4">
                {groups.map(([groupName, groupRules]) => (
                    <Card key={groupName}>
                        <CardHeader>
                            <CardTitle>{groupName}</CardTitle>
                            <CardDescription>
                                {groupRules.length}{' '}
                                {groupRules.length === 1 ? 'rule' : 'rules'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y border-t">
                                {groupRules.map((rule) => {
                                    const path = categoryPath(
                                        rule,
                                        categoryOptions,
                                    );
                                    const childName =
                                        path.split(' > ').at(-1) ?? path;

                                    return (
                                        <div
                                            key={rule.id}
                                            className="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="truncate font-medium">
                                                        {rule.merchant}
                                                    </h3>
                                                    <Badge variant="outline">
                                                        {childName}
                                                    </Badge>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {rule.transaction_kind ??
                                                        'Any kind'}{' '}
                                                    ·{' '}
                                                    {rule.currency ??
                                                        'Any currency'}
                                                </p>
                                            </div>
                                            <label className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    defaultChecked={
                                                        rule.enabled
                                                    }
                                                    aria-label={`Enable ${rule.merchant}`}
                                                />
                                                Enabled
                                            </label>
                                            <RuleActions rule={rule} />
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}
