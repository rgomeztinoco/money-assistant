import { Link } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/date-time';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { movementDescription } from '@/lib/money-movement';
import type { Currency, MoneyMovementDetails } from '@/types';

export type TransactionTableRow = MoneyMovementDetails & {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    description: string;
    category: { id: number; name: string } | null;
};

export function TransactionTable<T extends TransactionTableRow>({
    transactions,
    total,
    page,
    onPageChange,
    rowHref,
    onBeforeOpen,
    renderCategory,
    rowTestId,
}: {
    transactions: T[];
    total: number;
    page: number;
    onPageChange: (page: number) => void;
    rowHref: (transaction: T) => string;
    onBeforeOpen?: () => void;
    renderCategory?: (transaction: T) => ReactNode;
    rowTestId?: (transaction: T) => string;
}) {
    const lastPage = Math.max(1, Math.ceil(total / 50));

    return (
        <div className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2 px-4 type-meta">
                <span data-test="transaction-matching-count">
                    {total} matching{' '}
                    {total === 1 ? 'Transaction' : 'Transactions'}
                </span>
                {total > 0 && (
                    <span>
                        Page {page} of {lastPage}
                    </span>
                )}
            </div>
            {transactions.length === 0 ? (
                <div className="grid min-h-48 place-items-center p-8 text-center">
                    <div className="grid gap-2">
                        <p className="font-medium">No matching Transactions</p>
                        <p className="type-body text-muted-foreground">
                            Change the search or filters to see more results.
                        </p>
                    </div>
                </div>
            ) : (
                <Table className="block sm:table">
                    <TableHeader className="sticky top-0 z-10 hidden bg-background sm:table-header-group">
                        <TableRow className="hover:bg-background">
                            <TableHead className="pl-4">Description</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead className="w-10 pr-4">
                                <span className="sr-only">Open</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody className="block divide-y sm:table-row-group sm:divide-y-0">
                        {transactions.map((transaction) => {
                            const isMoneyIn =
                                transaction.direction === 'credit';
                            const DirectionIcon = isMoneyIn
                                ? ArrowDownLeft
                                : ArrowUpRight;

                            return (
                                <TableRow
                                    key={transaction.id}
                                    className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-2 gap-y-2 border-0 p-3 sm:table-row sm:border-b sm:p-0"
                                >
                                    <TableCell className="order-1 min-w-0 p-0 whitespace-normal sm:table-cell sm:min-w-52 sm:py-3 sm:pl-4">
                                        <div className="flex items-start gap-3">
                                            <span
                                                className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full ${isMoneyIn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}
                                            >
                                                <DirectionIcon className="size-4" />
                                            </span>
                                            <span className="grid min-w-0 gap-0.5">
                                                <span className="wrap-break-word">
                                                    {transaction.description}
                                                </span>
                                                <span className="type-meta tabular-nums">
                                                    <DateText
                                                        value={
                                                            transaction.occurred_on
                                                        }
                                                        format="weekday"
                                                    />{' '}
                                                    ·{' '}
                                                    {movementDescription({
                                                        kind: transaction.kind,
                                                        transferPurpose:
                                                            transaction.transfer_purpose,
                                                    })}
                                                </span>
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className="order-4 col-span-3 min-w-0 p-0 whitespace-normal sm:table-cell sm:min-w-44 sm:p-2">
                                        {renderCategory?.(transaction) ??
                                            transaction.category?.name ??
                                            'Uncategorized'}
                                    </TableCell>
                                    <TableCell
                                        className={`order-2 p-0 text-right tabular-nums sm:p-2 ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                                    >
                                        {isMoneyIn ? '+' : '−'}
                                        {formatMinorUnits(
                                            transaction.amount_minor,
                                            transaction.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="order-3 p-0 text-right sm:p-2 sm:pr-4">
                                        <Button
                                            asChild
                                            size="icon"
                                            variant="ghost"
                                        >
                                            <Link
                                                href={rowHref(transaction)}
                                                onClick={onBeforeOpen}
                                                preserveScroll
                                                preserveState
                                                data-test={rowTestId?.(
                                                    transaction,
                                                )}
                                                aria-label={`Open ${transaction.description}`}
                                            >
                                                <ChevronRight />
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
            {total > 50 && (
                <div className="flex items-center justify-end gap-2 px-4 pb-4">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={page <= 1}
                        onClick={() => onPageChange(page - 1)}
                    >
                        Previous
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={page >= lastPage}
                        onClick={() => onPageChange(page + 1)}
                    >
                        Next
                    </Button>
                </div>
            )}
        </div>
    );
}
