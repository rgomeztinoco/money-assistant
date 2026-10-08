import { router } from '@inertiajs/react';
import { useState } from 'react';
import { update as updateClassification } from '@/actions/App/Http/Controllers/BreakdownTransactionClassificationController';
import { CategoryPicker } from '@/components/category-picker';
import InputError from '@/components/input-error';
import { incomeSourceLabel, transferPurposeLabel } from '@/lib/money-movement';
import type { CategoryOption, Currency, MoneyMovementDetails } from '@/types';

type CategorizedTransaction = MoneyMovementDetails & {
    id: number;
    description: string;
    currency: Currency;
    category: { id: number; name: string } | null;
    has_split?: boolean;
};

export function TransactionCategorySelect({
    transaction,
    categoryOptions,
}: {
    transaction: CategorizedTransaction;
    categoryOptions: CategoryOption[];
}) {
    const currentCategoryId = transaction.category?.id.toString() ?? '';
    const [categoryId, setCategoryId] = useState(currentCategoryId);
    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    function submitCategory(value: string): void {
        if (value === currentCategoryId || processing) {
            return;
        }

        setCategoryId(value);
        setProcessing(true);
        setActionError(null);
        router.put(
            updateClassification(transaction.id),
            {
                category_id: value === '' ? null : Number(value),
                apply_to_matching: false,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    setCategoryId(currentCategoryId);
                    setActionError(
                        errors.category_id ?? 'Could not update the Category.',
                    );
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    if (transaction.has_split) {
        return <span className="text-muted-foreground">Category split</span>;
    }

    if (transaction.kind === 'debt') {
        return (
            <span className="text-muted-foreground">
                {transaction.category?.name ?? 'Debt payment'}
            </span>
        );
    }

    if (transaction.kind === 'income') {
        return (
            <span className="text-muted-foreground">
                {incomeSourceLabel(transaction.income_source)}
            </span>
        );
    }

    if (transaction.kind === 'transfer') {
        return (
            <span className="text-muted-foreground">
                {transferPurposeLabel(transaction.transfer_purpose)}
            </span>
        );
    }

    return (
        <div className="flex min-w-48 flex-col gap-2">
            <CategoryPicker
                id={`category-${transaction.id}`}
                name={`category-${transaction.id}`}
                options={categoryOptions}
                value={categoryId}
                onValueChange={submitCategory}
                emptyLabel="Uncategorized"
                ariaLabel={`Category for ${transaction.description}`}
                disabled={processing}
                className="h-auto min-h-8 px-2 py-1.5 text-left whitespace-normal"
                portalToBody
            />
            <InputError message={actionError ?? undefined} />
        </div>
    );
}
