<?php

namespace App\Actions\Breakdown;

use App\CategoryAssignmentProvenance;
use App\IncomeSource;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassifyBreakdownTransaction
{
    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    public function handle(
        User $owner,
        Transaction $transaction,
        ?int $categoryId,
        ?IncomeSource $incomeSource,
        bool $applyToMatching,
    ): int {
        return DB::transaction(function () use ($owner, $transaction, $categoryId, $incomeSource, $applyToMatching): int {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentTransaction->kind->supportsCategory()) {
                return $this->classifySpending(
                    $owner,
                    $currentTransaction,
                    $categoryId,
                    $applyToMatching,
                );
            }

            if ($currentTransaction->kind->value === 'income') {
                if ($incomeSource === null) {
                    throw ValidationException::withMessages([
                        'income_source' => 'Choose an Income Source.',
                    ]);
                }

                $currentTransaction->income_source = $incomeSource;
                $currentTransaction->save();

                return 1;
            }

            throw ValidationException::withMessages([
                'classification' => 'This Transaction has no editable classification.',
            ]);
        }, 3);
    }

    private function classifySpending(
        User $owner,
        Transaction $transaction,
        ?int $categoryId,
        bool $applyToMatching,
    ): int {
        $category = $categoryId === null
            ? null
            : Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

        if ($categoryId !== null && $category === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Choose an active Category owned by you.',
            ]);
        }

        if (! $applyToMatching) {
            $transaction->category_id = $category?->id;
            $transaction->category_assignment_provenance = $category === null
                ? null
                : CategoryAssignmentProvenance::Owner;
            $transaction->merchant_rule_id = null;
            $transaction->save();

            return 1;
        }

        if ($category === null) {
            throw ValidationException::withMessages([
                'category_id' => 'Choose a Category before applying an exact merchant match.',
            ]);
        }

        $merchantKey = $this->merchantNormalizer->normalize($transaction->description);
        $merchantRule = MerchantRule::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('merchant_key', $merchantKey)
            ->where('transaction_kind', $transaction->kind)
            ->where('currency', $transaction->currency)
            ->lockForUpdate()
            ->first();

        $merchantRule ??= new MerchantRule;
        $merchantRule->fill([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'merchant' => $transaction->description,
            'merchant_key' => $merchantKey,
            'transaction_kind' => $transaction->kind,
            'currency' => $transaction->currency,
            'enabled' => true,
        ])->save();
        $matchingTransactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->where('kind', $transaction->kind)
            ->where('currency', $transaction->currency)
            ->lockForUpdate()
            ->get(['id', 'description']);
        $matchingTransactionIds = $matchingTransactions
            ->filter(fn (Transaction $candidate): bool => $this->merchantNormalizer->normalize($candidate->description) === $merchantKey)
            ->pluck('id');

        Transaction::query()
            ->whereKey($matchingTransactionIds)
            ->update([
                'category_id' => $category->id,
                'category_assignment_provenance' => CategoryAssignmentProvenance::MerchantRule,
                'merchant_rule_id' => $merchantRule->id,
                'updated_at' => now(),
            ]);

        return $matchingTransactionIds->count();
    }
}
