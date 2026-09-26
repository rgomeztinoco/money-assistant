import { ArrowDownLeft, ArrowUpRight, MoreHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/date-time';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import { movementDescription, movementKindLabel } from '@/lib/money-movement';
import type { Currency, MoneyMovementDetails } from '@/types';

export type TransactionTableRow = MoneyMovementDetails & {
    id: number;
    occurred_on: string;
    amount_minor: string;
    currency: Currency;
    description: string;
    category: { id: number; name: string } | null;
    voided_at?: string | null;
};

export type TransactionAction = 'edit' | 'void' | 'restore' | 'rule' | 'split';

export function TransactionTable<T extends TransactionTableRow>({
    transactions,
    total,
    page,
    onPageChange,
    onAction,
    onBeforeOpen,
    renderCategory,
    rowTestId,
    expanded = false,
}: {
    transactions: T[];
    total: number;
    page: number;
    onPageChange: (page: number) => void;
    onAction: (transaction: T, action: TransactionAction) => void;
    onBeforeOpen?: () => void;
    renderCategory?: (transaction: T) => ReactNode;
    rowTestId?: (transaction: T) => string;
    expanded?: boolean;
}) {
    const lastPage = Math.max(1, Math.ceil(total / 50));

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
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
                <div
                    className="max-h-[calc(100dvh-16rem)] min-h-0 min-w-0 flex-1 overflow-auto"
                    data-test="breakdown-transactions-scroll"
                >
                    <table className="block w-full caption-bottom type-row sm:table sm:min-w-max">
                        <TableHeader className="sticky top-0 z-10 hidden bg-background sm:table-header-group">
                            <TableRow className="hover:bg-background">
                                <TableHead className="pl-4">ID</TableHead>
                                <TableHead className="pl-4">
                                    Description
                                </TableHead>
                                {expanded && <TableHead>Date</TableHead>}
                                {expanded && <TableHead>Kind</TableHead>}
                                <TableHead>Category</TableHead>
                                {expanded && <TableHead>Currency</TableHead>}
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead className="w-10 pr-4">
                                    <span className="sr-only">Actions</span>
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
                                        <TableCell
                                            className="order-5 col-span-3 p-0 text-muted-foreground tabular-nums sm:table-cell sm:pl-4"
                                            data-test={`transaction-row-id-${transaction.id}`}
                                        >
                                            <span className="sm:hidden">
                                                ID{' '}
                                            </span>
                                            #{transaction.id}
                                        </TableCell>
                                        <TableCell className="order-1 min-w-0 p-0 whitespace-normal sm:table-cell sm:min-w-52 sm:py-3 sm:pl-4">
                                            <div className="flex items-start gap-3">
                                                <span
                                                    className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full ${isMoneyIn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}
                                                >
                                                    <DirectionIcon className="size-4" />
                                                </span>
                                                <span className="grid min-w-0 gap-0.5">
                                                    <span className="wrap-break-word">
                                                        {
                                                            transaction.description
                                                        }
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
                                        {expanded && (
                                            <TableCell className="hidden tabular-nums sm:table-cell">
                                                <DateText
                                                    value={
                                                        transaction.occurred_on
                                                    }
                                                    format="weekday"
                                                />
                                            </TableCell>
                                        )}
                                        {expanded && (
                                            <TableCell className="hidden sm:table-cell">
                                                {movementKindLabel(
                                                    transaction.kind,
                                                )}
                                            </TableCell>
                                        )}
                                        <TableCell className="order-4 col-span-3 min-w-0 p-0 whitespace-normal sm:table-cell sm:min-w-44 sm:p-2">
                                            {renderCategory?.(transaction) ??
                                                transaction.category?.name ??
                                                'Uncategorized'}
                                        </TableCell>
                                        {expanded && (
                                            <TableCell className="hidden sm:table-cell">
                                                {transaction.currency}
                                            </TableCell>
                                        )}
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
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        data-test={rowTestId?.(
                                                            transaction,
                                                        )}
                                                        aria-label={`Actions for ${transaction.description}`}
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() => {
                                                            onBeforeOpen?.();
                                                            onAction(
                                                                transaction,
                                                                'edit',
                                                            );
                                                        }}
                                                    >
                                                        Edit
                                                    </DropdownMenuItem>
                                                    {!transaction.voided_at &&
                                                        (transaction.kind ===
                                                            'spending' ||
                                                            transaction.kind ===
                                                                'refund') && (
                                                            <>
                                                                <DropdownMenuItem
                                                                    onSelect={() => {
                                                                        onBeforeOpen?.();
                                                                        onAction(
                                                                            transaction,
                                                                            'split',
                                                                        );
                                                                    }}
                                                                >
                                                                    Split by
                                                                    Category
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    onSelect={() => {
                                                                        onBeforeOpen?.();
                                                                        onAction(
                                                                            transaction,
                                                                            'rule',
                                                                        );
                                                                    }}
                                                                >
                                                                    Create
                                                                    Merchant
                                                                    Rule
                                                                </DropdownMenuItem>
                                                            </>
                                                        )}
                                                    <DropdownMenuItem
                                                        variant={
                                                            transaction.voided_at
                                                                ? 'default'
                                                                : 'destructive'
                                                        }
                                                        onSelect={() => {
                                                            onBeforeOpen?.();
                                                            onAction(
                                                                transaction,
                                                                transaction.voided_at
                                                                    ? 'restore'
                                                                    : 'void',
                                                            );
                                                        }}
                                                    >
                                                        {transaction.voided_at
                                                            ? 'Restore Transaction'
                                                            : 'Void Transaction'}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </table>
                </div>
            )}
            <div className="flex shrink-0 items-center justify-between gap-2 border-t px-4 py-3 type-meta">
                <span>
                    Page {page} of {lastPage}
                </span>
                {total > 50 && (
                    <div className="flex items-center gap-2">
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
        </div>
    );
}
