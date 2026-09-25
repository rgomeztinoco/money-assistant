import { Filter, Search } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { currencyUnitsToMinorUnits } from '@/lib/format-minor-units';
import { movementKindLabel } from '@/lib/money-movement';
import type { Currency, TransactionKind } from '@/types';

const allKinds: TransactionKind[] = [
    'spending',
    'refund',
    'income',
    'transfer',
];

export type TransactionListFilters = {
    amount_min: string | null;
    amount_max: string | null;
    kinds: TransactionKind[];
    currency?: Currency | null;
    date_from?: string | null;
    date_to?: string | null;
};

function selectedKinds(kinds: TransactionKind[]): TransactionKind[] {
    return kinds.length === 0 ? allKinds : kinds;
}

export function TransactionListFilterControls({
    search,
    filters,
    instantSearch,
    includeDates = false,
    includeCurrency = false,
    secondarySearch,
    onSearch,
    onApply,
}: {
    search: string;
    filters: TransactionListFilters;
    instantSearch: boolean;
    includeDates?: boolean;
    includeCurrency?: boolean;
    secondarySearch?: ReactNode;
    onSearch: (search: string) => void;
    onApply: (filters: TransactionListFilters) => void;
}) {
    const [searchValue, setSearchValue] = useState(search);
    const [draft, setDraft] = useState(filters);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function submitSearch(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        onSearch(searchValue);
    }

    function applyFilters(): void {
        if (
            includeCurrency &&
            !draft.currency &&
            (draft.amount_min || draft.amount_max)
        ) {
            setError('Select a currency to filter by amount.');

            return;
        }

        const minimum = draft.amount_min
            ? currencyUnitsToMinorUnits(draft.amount_min)
            : null;
        const maximum = draft.amount_max
            ? currencyUnitsToMinorUnits(draft.amount_max)
            : null;

        if (
            (draft.amount_min && (minimum === null || minimum < 0n)) ||
            (draft.amount_max && (maximum === null || maximum < 0n))
        ) {
            setError(
                'Enter a nonnegative amount with at most two decimal places.',
            );

            return;
        }

        if (minimum !== null && maximum !== null && minimum > maximum) {
            setError('Maximum amount must be at least the minimum amount.');

            return;
        }

        if (
            draft.date_from &&
            draft.date_to &&
            draft.date_from > draft.date_to
        ) {
            setError('End date must be on or after the start date.');

            return;
        }

        if (draft.kinds.length === 0) {
            setError('Select at least one Transaction Kind.');

            return;
        }

        onApply({
            ...draft,
            amount_min: draft.amount_min || null,
            amount_max: draft.amount_max || null,
            kinds: draft.kinds.length === allKinds.length ? [] : draft.kinds,
        });
        setError(null);
        setOpen(false);
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <form
                onSubmit={submitSearch}
                className="flex max-w-sm min-w-48 flex-1 gap-2"
            >
                <div className="min-w-0 flex-1">
                    <Input
                        id="transaction-search"
                        aria-label="Merchant or description"
                        placeholder="Merchant or description"
                        value={searchValue}
                        onChange={(event) => {
                            const value = event.target.value;
                            setSearchValue(value);

                            if (instantSearch) {
                                onSearch(value);
                            }
                        }}
                    />
                </div>
                {!instantSearch && (
                    <Button type="submit" data-test="transaction-search-submit">
                        <Search /> Search
                    </Button>
                )}
            </form>
            {secondarySearch}
            <Popover
                open={open}
                onOpenChange={(nextOpen) => {
                    setOpen(nextOpen);

                    if (nextOpen) {
                        setDraft({
                            ...filters,
                            kinds: selectedKinds(filters.kinds),
                        });
                        setError(null);
                    }
                }}
            >
                <PopoverTrigger
                    render={<Button type="button" variant="outline" />}
                >
                    <Filter /> Filters
                </PopoverTrigger>
                <PopoverContent
                    align="end"
                    className="w-[min(22rem,calc(100vw-2rem))] p-4"
                >
                    <div className="grid gap-4">
                        {includeCurrency && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="filter-currency">
                                    Currency
                                </Label>
                                <NativeSelect
                                    id="filter-currency"
                                    value={draft.currency ?? ''}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            currency:
                                                (event.target
                                                    .value as Currency) || null,
                                        })
                                    }
                                    options={[
                                        { value: '', label: 'All currencies' },
                                        { value: 'PEN', label: 'PEN' },
                                        { value: 'USD', label: 'USD' },
                                    ]}
                                />
                            </div>
                        )}
                        {includeDates && (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="filter-date-from">
                                        From
                                    </Label>
                                    <Input
                                        id="filter-date-from"
                                        type="date"
                                        value={draft.date_from ?? ''}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                date_from: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="filter-date-to">To</Label>
                                    <Input
                                        id="filter-date-to"
                                        type="date"
                                        value={draft.date_to ?? ''}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                date_to: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="filter-amount-min">
                                    Minimum amount
                                </Label>
                                <Input
                                    id="filter-amount-min"
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    value={draft.amount_min ?? ''}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            amount_min: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="filter-amount-max">
                                    Maximum amount
                                </Label>
                                <Input
                                    id="filter-amount-max"
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    value={draft.amount_max ?? ''}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            amount_max: event.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        <fieldset className="grid gap-2">
                            <legend className="font-medium">
                                Transaction Kind
                            </legend>
                            <div className="grid grid-cols-2 gap-2">
                                {allKinds.map((kind) => (
                                    <div
                                        key={kind}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`filter-kind-${kind}`}
                                            checked={draft.kinds.includes(kind)}
                                            onCheckedChange={(checked) =>
                                                setDraft({
                                                    ...draft,
                                                    kinds: checked
                                                        ? [...draft.kinds, kind]
                                                        : draft.kinds.filter(
                                                              (value) =>
                                                                  value !==
                                                                  kind,
                                                          ),
                                                })
                                            }
                                        />
                                        <Label htmlFor={`filter-kind-${kind}`}>
                                            {movementKindLabel(kind)}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        </fieldset>
                        {error && (
                            <p role="alert" className="text-destructive">
                                {error}
                            </p>
                        )}
                        <Button type="button" onClick={applyFilters}>
                            Apply
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    );
}
