<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignCategoryToTransaction
{
    public function handle(
        User $owner,
        int $transactionId,
        int $expectedRevision,
        ?int $categoryId,
        ?int $expectedCategoryRevision = null,
    ): Transaction {
        return DB::transaction(function () use ($owner, $transactionId, $expectedRevision, $categoryId, $expectedCategoryRevision): Transaction {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->revision !== $expectedRevision) {
                throw StaleTransactionRevision::fromTransaction($transaction);
            }

            $category = $categoryId === null
                ? null
                : Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($categoryId)
                    ->whereNull('retired_at')
                    ->lockForUpdate()
                    ->first();

            if ($categoryId !== null && $category === null) {
                throw ValidationException::withMessages([
                    'category_id' => 'Choose an active Category owned by you.',
                ]);
            }

            if ($category !== null
                && $expectedCategoryRevision !== null
                && $category->revision !== $expectedCategoryRevision) {
                throw new StaleCategoryRevision;
            }

            $transaction->category_id = $category?->id;
            $transaction->category_assignment_provenance = $category === null
                ? null
                : CategoryAssignmentProvenance::Owner;
            $transaction->revision++;
            $transaction->save();

            return $transaction;
        }, 3);
    }
}
