import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { cn } from '@/lib/utils';
import { transactions as searchTransactions } from '@/routes/debts';
import type { DebtTransactionOption } from './types';

type SearchResult = {
    transactions: DebtTransactionOption[];
    has_more: boolean;
};

export function DebtTransactionPicker({
    debtId,
    direction,
    value,
    onSelect,
    invalid,
}: {
    debtId: number;
    direction: 'debit' | 'credit';
    value: string;
    onSelect: (transaction: DebtTransactionOption) => void;
    invalid: boolean;
}) {
    const container = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<DebtTransactionOption | null>(
        null,
    );
    const [result, setResult] = useState<
        (SearchResult & { key: string }) | null
    >(null);
    const [error, setError] = useState<string | null>(null);
    const [retry, setRetry] = useState(0);
    const key = `${debtId}:${direction}:${query}:${page}:${retry}`;
    const loading = result?.key !== key && error === null;

    useEffect(() => {
        if (!open || result?.key === key) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(
                searchTransactions.url(debtId, {
                    query: { direction, search: query, page },
                }),
                {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                },
            )
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Search failed');
                    }

                    return response.json() as Promise<SearchResult>;
                })
                .then((data) => {
                    setResult((previous) => ({
                        ...data,
                        key,
                        transactions:
                            page === 1
                                ? data.transactions
                                : [
                                      ...(previous?.transactions ?? []),
                                      ...data.transactions,
                                  ],
                    }));
                    setError(null);
                })
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setError('Could not load Transactions.');
                    }
                });
        }, 200);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [debtId, direction, query, page, open, retry, key, result?.key]);

    return (
        <div ref={container} className="contents">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger
                    render={
                        <Button
                            id="entry-transaction"
                            type="button"
                            variant="outline"
                            role="combobox"
                            aria-label="Choose a Transaction"
                            aria-describedby="entry-transaction-filters"
                            aria-controls="debt-transaction-options"
                            aria-expanded={open}
                            aria-invalid={invalid}
                            className="w-full justify-between"
                        />
                    }
                >
                    <span className="truncate">
                        {selected && String(selected.id) === value
                            ? `${selected.occurred_on} · ${selected.description}`
                            : 'Choose a Transaction'}
                    </span>
                    <ChevronsUpDown />
                </PopoverTrigger>
                <PopoverContent
                    container={container}
                    align="start"
                    className="max-h-(--available-height) w-(--anchor-width) gap-0 overflow-hidden p-0"
                >
                    <Command shouldFilter={false}>
                        <CommandInput
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    event.stopPropagation();
                                    setOpen(false);
                                }
                            }}
                            aria-label="Search Transactions"
                            placeholder="Search description, date, or ID"
                            value={query}
                            onValueChange={(next) => {
                                setQuery(next);
                                setPage(1);
                                setError(null);
                            }}
                        />
                        <CommandList
                            id="debt-transaction-options"
                            aria-busy={loading}
                        >
                            {loading ? (
                                <div
                                    role="status"
                                    className="flex items-center gap-2 p-3 type-meta"
                                >
                                    <Spinner /> Searching…
                                </div>
                            ) : error ? (
                                <div className="flex items-center justify-between gap-2 p-3">
                                    <span role="alert">{error}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => {
                                            setError(null);
                                            setRetry((current) => current + 1);
                                        }}
                                    >
                                        Retry
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <CommandEmpty>
                                        No Transactions match these filters.
                                    </CommandEmpty>
                                    <CommandGroup>
                                        {result?.transactions.map(
                                            (transaction) => (
                                                <CommandItem
                                                    key={transaction.id}
                                                    value={String(
                                                        transaction.id,
                                                    )}
                                                    data-test={`debt-transaction-${transaction.id}`}
                                                    onSelect={() => {
                                                        setSelected(
                                                            transaction,
                                                        );
                                                        onSelect(transaction);
                                                        setOpen(false);
                                                    }}
                                                >
                                                    <Check
                                                        className={cn(
                                                            value ===
                                                                String(
                                                                    transaction.id,
                                                                )
                                                                ? 'opacity-100'
                                                                : 'opacity-0',
                                                        )}
                                                    />
                                                    <div className="flex min-w-0 flex-1 flex-col">
                                                        <span className="truncate">
                                                            {
                                                                transaction.description
                                                            }
                                                        </span>
                                                        <span className="type-meta text-muted-foreground">
                                                            {
                                                                transaction.occurred_on
                                                            }{' '}
                                                            · #{transaction.id}
                                                        </span>
                                                    </div>
                                                    <span className="shrink-0 tabular-nums">
                                                        {formatMinorUnits(
                                                            transaction.amount_minor,
                                                            transaction.currency,
                                                        )}
                                                    </span>
                                                </CommandItem>
                                            ),
                                        )}
                                    </CommandGroup>
                                    {result?.has_more && (
                                        <CommandGroup>
                                            <CommandItem
                                                value="load-more"
                                                onSelect={() =>
                                                    setPage(
                                                        (current) =>
                                                            current + 1,
                                                    )
                                                }
                                            >
                                                Load more Transactions
                                            </CommandItem>
                                        </CommandGroup>
                                    )}
                                </>
                            )}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </div>
    );
}
