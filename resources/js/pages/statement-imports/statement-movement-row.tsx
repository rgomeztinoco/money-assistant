import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { formatMinorUnits } from '@/lib/format-minor-units';
import {
    statementMovementClassificationLabel,
    statementMovementContributesToSpending,
} from '@/lib/statement-movement-classification';
import type {
    Currency,
    StatementClassification,
    StatementDirection,
} from '@/types';

export default function StatementMovementRow({
    position,
    occurredOn,
    description,
    amountMinor,
    currency,
    direction,
    classification,
    dataTest,
    detail,
    action,
}: {
    position: number;
    occurredOn: string;
    description: string;
    amountMinor: string;
    currency: Currency;
    direction: StatementDirection;
    classification: StatementClassification;
    dataTest?: string;
    detail?: ReactNode;
    action?: ReactNode;
}) {
    const isUnresolved = classification === 'needs_classification';
    const affectsNetSpending =
        statementMovementContributesToSpending(classification);
    const isMoneyIn = direction === 'credit';
    const DirectionIcon = isMoneyIn ? ArrowDownLeft : ArrowUpRight;

    return (
        <TableRow
            data-test={dataTest}
            className={isUnresolved ? 'bg-destructive/5' : undefined}
        >
            <TableCell className="pl-6">
                <span
                    className={`flex size-9 items-center justify-center rounded-full ${isMoneyIn ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-muted text-muted-foreground'}`}
                >
                    <DirectionIcon className="size-4" />
                    <span className="sr-only">
                        {isMoneyIn ? 'Money in' : 'Money out'}
                    </span>
                </span>
            </TableCell>
            <TableCell className="max-w-80 min-w-64 whitespace-normal">
                <div className="grid min-w-0 gap-1">
                    <span className="font-medium break-words">
                        {description}
                    </span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {occurredOn} · Movement {position}
                    </span>
                    {detail}
                </div>
            </TableCell>

            <TableCell
                className={`text-right font-semibold tabular-nums ${isMoneyIn ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
            >
                {isMoneyIn ? '+' : '−'}
                {formatMinorUnits(amountMinor || '0', currency)}
            </TableCell>

            <TableCell className="min-w-48 whitespace-normal">
                <Badge variant={isUnresolved ? 'destructive' : 'outline'}>
                    {statementMovementClassificationLabel(classification)}
                </Badge>
            </TableCell>

            <TableCell className="min-w-44 whitespace-normal">
                {!isUnresolved && (
                    <Badge
                        variant={affectsNetSpending ? 'default' : 'secondary'}
                    >
                        {affectsNetSpending
                            ? 'Affects Net Spending'
                            : 'Outside Net Spending'}
                    </Badge>
                )}
            </TableCell>

            <TableCell className="pr-6 text-right">{action}</TableCell>
        </TableRow>
    );
}
