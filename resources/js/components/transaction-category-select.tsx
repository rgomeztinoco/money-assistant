import { router } from '@inertiajs/react';
import { useState } from 'react';
import { update as updateClassification } from '@/actions/App/Http/Controllers/BreakdownTransactionClassificationController';
import { store as storeMerchantRule } from '@/actions/App/Http/Controllers/MerchantRuleController';
import { CategoryPicker } from '@/components/category-picker';
import { Button } from '@/components/ui/button';
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
    const [processingAction, setProcessingAction] = useState<
        'once' | 'rule' | null
    >(null);
    const [pickerVersion, setPickerVersion] = useState(0);
    const [actionError, setActionError] = useState<string | null>(null);
    const hasPendingCategory = categoryId !== currentCategoryId;

    function submitCategory(): void {
        if (!hasPendingCategory) {
            return;
        }

        setProcessingAction('once');
        setActionError(null);
        router.put(
            updateClassification(transaction.id),
            {
                category_id: categoryId === '' ? null : Number(categoryId),
                apply_to_matching: false,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setPickerVersion((version) => version + 1),
                onError: (errors) =>
                    setActionError(
                        errors.category_id ?? 'Could not update the Category.',
                    ),
                onFinish: () => setProcessingAction(null),
            },
        );
    }

    function createMerchantRule(): void {
        if (categoryId === '') {
            return;
        }

        setProcessingAction('rule');
        setActionError(null);
        router.post(
            storeMerchantRule(),
            {
                merchant: transaction.description,
                category_id: Number(categoryId),
                transaction_kind: transaction.kind,
                currency: transaction.currency,
                enabled: true,
                apply_existing: false,
                source_transaction_id: transaction.id,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setCategoryId(currentCategoryId);
                    setPickerVersion((version) => version + 1);
                },
                onError: (errors) =>
                    setActionError(
                        errors.merchant ??
                            errors.enabled ??
                            errors.category_id ??
                            'Could not create the Merchant Rule.',
                    ),
                onFinish: () => setProcessingAction(null),
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
                key={pickerVersion}
                id={`category-${transaction.id}`}
                name={`category-${transaction.id}`}
                options={categoryOptions}
                value={categoryId}
                onValueChange={(value) => {
                    setCategoryId(value);
                    setActionError(null);
                }}
                emptyLabel="Uncategorized"
                ariaLabel={`Category for ${transaction.description}`}
                disabled={processingAction !== null}
                className="h-auto min-h-8 px-2 py-1.5 text-left whitespace-normal"
                closeOnSelect={false}
                portalToBody
                popoverFooter={
                    hasPendingCategory || categoryId !== '' ? (
                        <div
                            className="grid gap-2 p-2"
                            data-test={`category-confirmation-${transaction.id}`}
                        >
                            {actionError && (
                                <p
                                    role="alert"
                                    className="type-meta text-destructive"
                                >
                                    {actionError}
                                </p>
                            )}
                            <div className="flex flex-wrap justify-end gap-1.5">
                                {hasPendingCategory && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        data-test={`apply-category-once-${transaction.id}`}
                                        disabled={processingAction !== null}
                                        onClick={submitCategory}
                                    >
                                        {processingAction === 'once'
                                            ? 'Applying…'
                                            : 'Apply once'}
                                    </Button>
                                )}
                                {categoryId !== '' && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        data-test={`create-merchant-rule-${transaction.id}`}
                                        disabled={processingAction !== null}
                                        onClick={createMerchantRule}
                                    >
                                        {processingAction === 'rule'
                                            ? 'Creating…'
                                            : 'Create Merchant Rule'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : undefined
                }
            />
        </div>
    );
}
