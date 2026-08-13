<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignCategoryToTransaction
{
    public function handle(int $transactionId, ?int $categoryId): Transaction
    {
        return DB::transaction(function () use ($transactionId, $categoryId): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();
            $category = $categoryId === null
                ? null
                : Category::query()
                    ->whereKey($categoryId)
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->first();

            if ($categoryId !== null && $category === null) {
                throw ValidationException::withMessages([
                    'category_id' => 'Choose an active Category.',
                ]);
            }

            $transaction->category_id = $category?->id;
            $transaction->category_assignment_provenance = $category === null
                ? null
                : CategoryAssignmentProvenance::Owner;
            $transaction->merchant_rule_id = null;
            $transaction->save();

            return $transaction;
        }, 3);
    }
}
