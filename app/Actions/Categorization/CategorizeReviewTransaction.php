<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CategorizeReviewTransaction
{
    public function __construct(
        private MerchantNormalizer $merchantNormalizer,
        private SaveMerchantRule $saveMerchantRule,
    ) {}

    /** @return array{assigned_transaction_count: int, merchant_rule_created: bool} */
    public function handle(
        User $owner,
        Transaction $transaction,
        int $categoryId,
        bool $createMerchantRule,
        bool $bulkAssign,
        ?TransactionKind $ruleTransactionKind,
        ?Currency $ruleCurrency,
    ): array {
        return DB::transaction(function () use (
            $owner,
            $transaction,
            $categoryId,
            $createMerchantRule,
            $bulkAssign,
            $ruleTransactionKind,
            $ruleCurrency,
        ): array {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->firstOrFail();

            if (! $currentTransaction->kind->supportsCategory()) {
                throw ValidationException::withMessages([
                    'category_id' => 'Categories are available only for Spending and Refund Transactions.',
                ]);
            }

            if ($currentTransaction->category_id !== null) {
                throw ValidationException::withMessages([
                    'category_id' => 'This Transaction already has a Category.',
                ]);
            }

            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'category_id' => 'Choose an active Category owned by you.',
                ]);
            }

            $assignedTransactionCount = 0;

            if ($bulkAssign) {
                $merchantKey = $this->merchantNormalizer->normalize($currentTransaction->description);
                $transactionsToAssign = Transaction::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereNull('voided_at')
                    ->whereCategoryRequiresReview()
                    ->lockForUpdate()
                    ->lazyById(200);

                foreach ($transactionsToAssign as $transactionToAssign) {
                    if ($this->normalizedMerchant($transactionToAssign->description) !== $merchantKey) {
                        continue;
                    }

                    $this->assignCategory($transactionToAssign, $category->id);
                    $assignedTransactionCount++;
                }
            } else {
                $this->assignCategory($currentTransaction, $category->id);
                $assignedTransactionCount = 1;
            }

            if ($createMerchantRule) {
                $this->saveMerchantRule->handle(
                    owner: $owner,
                    merchant: $currentTransaction->description,
                    categoryId: $category->id,
                    transactionKind: $ruleTransactionKind,
                    currency: $ruleCurrency,
                    enabled: true,
                );
            }

            return [
                'assigned_transaction_count' => $assignedTransactionCount,
                'merchant_rule_created' => $createMerchantRule,
            ];
        }, 3);
    }

    private function assignCategory(Transaction $transaction, int $categoryId): void
    {
        $transaction->category_id = $categoryId;
        $transaction->category_assignment_provenance = CategoryAssignmentProvenance::Owner;
        $transaction->merchant_rule_id = null;
        $transaction->save();
    }

    private function normalizedMerchant(string $merchant): ?string
    {
        try {
            return $this->merchantNormalizer->normalize($merchant);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
