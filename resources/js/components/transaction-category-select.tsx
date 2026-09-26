import { router } from '@inertiajs/react';
import { useState } from 'react';
import { update as updateClassification } from '@/actions/App/Http/Controllers/BreakdownTransactionClassificationController';
import { CategoryPicker } from '@/components/category-picker';
import { Button } from '@/components/ui/button';
import { incomeSourceLabel, transferPurposeLabel } from '@/lib/money-movement';
import type { CategoryOption, MoneyMovementDetails } from '@/types';

type CategorizedTransaction = MoneyMovementDetails & {
    id: number;
    description: string;
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
    const [processingAction, setProcessingAction] = useState(false);
    const hasPendingCategory = categoryId !== currentCategoryId;

    function submitCategory(): void {
        if (!hasPendingCategory) {
            return;
        }

        setProcessingAction(true);
        router.put(
            updateClassification(transaction.id),
            {
                category_id: categoryId === '' ? null : Number(categoryId),
                apply_to_matching: false,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setProcessingAction(false),
            },
        );
    }

    if (transaction.has_split) {
        return <span className="text-muted-foreground">Category split</span>;
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
                onValueChange={setCategoryId}
                emptyLabel="Uncategorized"
                ariaLabel={`Category for ${transaction.description}`}
                disabled={processingAction}
                className="h-auto min-h-8 px-2 py-1.5 text-left whitespace-normal"
                closeOnSelect={false}
                portalToBody
                popoverFooter={
                    hasPendingCategory ? (
                        <div
                            className="flex items-center justify-end gap-1.5 p-2"
                            data-test={`category-confirmation-${transaction.id}`}
                        >
                            <Button
                                type="button"
                                size="sm"
                                data-test={`apply-category-once-${transaction.id}`}
                                disabled={processingAction}
                                onClick={submitCategory}
                            >
                                {processingAction ? 'Applying…' : 'Apply once'}
                            </Button>
                        </div>
                    ) : undefined
                }
            />
        </div>
    );
}
