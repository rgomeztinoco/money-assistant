import { Filter, MoreHorizontal, Plus, Search } from 'lucide-react';
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
import type { CategoryOption, MerchantRule } from '@/types';
import { categoryPath, prototypeRules } from './prototype-data';

function searchable(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase();
}

export function MerchantRulesBrowserPrototype({
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
    const [selectedCategory, setSelectedCategory] = useState<number | null>(
        null,
    );
    const normalizedQuery = searchable(query.trim());
    const visibleRules = prototype.rules.filter((rule) => {
        const path = categoryPath(rule, categoryOptions);

        return (
            (selectedCategory === null ||
                rule.category_id === selectedCategory) &&
            searchable(
                `${rule.merchant} ${rule.merchant_key} ${path}`,
            ).includes(normalizedQuery)
        );
    });
    const usedCategories = categoryOptions
        .map((category) => ({
            ...category,
            count: prototype.rules.filter(
                (rule) => rule.category_id === category.id,
            ).length,
        }))
        .filter((category) => category.count > 0)
        .sort((first, second) => first.path.localeCompare(second.path));

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
                        Browse categories on the left, then work with a compact
                        rule table.
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
                        placeholder="Search merchant rules"
                        aria-label="Search merchant rules"
                        className="pl-9"
                    />
                </div>
                <Button type="button" variant="outline">
                    <Filter data-icon="inline-start" /> Filters
                </Button>
            </div>

            <div className="grid min-h-[32rem] gap-4 lg:grid-cols-[19rem_minmax(0,1fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>Categories</CardTitle>
                        <CardDescription>
                            Only categories with rules
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            className={cn(
                                'h-auto justify-between px-3 py-2.5',
                                selectedCategory === null &&
                                    'bg-accent text-accent-foreground',
                            )}
                            onClick={() => setSelectedCategory(null)}
                        >
                            All rules
                            <Badge variant="secondary">
                                {prototype.rules.length}
                            </Badge>
                        </Button>
                        {usedCategories.map((category) => (
                            <Button
                                key={category.id}
                                type="button"
                                variant="ghost"
                                className={cn(
                                    'h-auto justify-between px-3 py-2.5 text-left whitespace-normal',
                                    selectedCategory === category.id &&
                                        'bg-accent text-accent-foreground',
                                )}
                                onClick={() => setSelectedCategory(category.id)}
                            >
                                <span className="min-w-0">{category.path}</span>
                                <Badge variant="secondary">
                                    {category.count}
                                </Badge>
                            </Button>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {selectedCategory === null
                                ? 'All rules'
                                : (categoryOptions.find(
                                      (category) =>
                                          category.id === selectedCategory,
                                  )?.path ?? 'Category rules')}
                        </CardTitle>
                        <CardDescription>
                            {visibleRules.length}{' '}
                            {visibleRules.length === 1 ? 'rule' : 'rules'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Merchant</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Scope</TableHead>
                                    <TableHead>Enabled</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visibleRules.map((rule) => (
                                    <TableRow key={rule.id}>
                                        <TableCell className="font-medium">
                                            {rule.merchant}
                                        </TableCell>
                                        <TableCell>
                                            {categoryPath(
                                                rule,
                                                categoryOptions,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {rule.transaction_kind ?? 'Any'} ·{' '}
                                            {rule.currency ?? 'Any'}
                                        </TableCell>
                                        <TableCell>
                                            <Checkbox
                                                defaultChecked={rule.enabled}
                                                aria-label={`Enable ${rule.merchant}`}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Actions for ${rule.merchant}`}
                                            >
                                                <MoreHorizontal />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
