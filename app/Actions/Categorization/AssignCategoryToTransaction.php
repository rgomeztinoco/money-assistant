<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Exceptions\StaleCategoryRevision;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Category;
use App\Models\CategoryAssignment;
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

            $categoryIdsToLock = collect([$transaction->category_id, $categoryId])
                ->filter(fn (?int $id): bool => $id !== null)
                ->unique()
                ->sort()
                ->values();
            $lockedCategories = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereIn('id', $categoryIdsToLock)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $category = $categoryId === null ? null : $lockedCategories->get($categoryId);

            if ($categoryId !== null && ($category === null || $category->retired_at !== null)) {
                throw ValidationException::withMessages([
                    'category_id' => 'Choose an active Category owned by you.',
                ]);
            }

            if ($category !== null
                && $expectedCategoryRevision !== null
                && $category->revision !== $expectedCategoryRevision) {
                throw new StaleCategoryRevision;
            }

            if ($transaction->category_id === $category?->id
                && $transaction->category_assignment_provenance === CategoryAssignmentProvenance::Owner) {
                return $transaction;
            }

            $previousCategoryId = $transaction->category_id;
            $isCategoryCorrection = $previousCategoryId !== $category?->id;
            $transaction->category_id = $category?->id;
            $transaction->category_assignment_provenance = $category === null
                ? null
                : CategoryAssignmentProvenance::Owner;
            $transaction->revision++;
            $transaction->save();

            CategoryAssignment::create([
                'user_id' => $owner->getKey(),
                'transaction_id' => $transaction->getKey(),
                'category_id' => $category?->id,
                'previous_category_id' => $previousCategoryId,
                'source' => CategoryAssignmentProvenance::Owner,
                'is_correction' => $isCategoryCorrection,
                'transaction_revision' => $transaction->revision,
            ]);

            return $transaction;
        }, 3);
    }
}
