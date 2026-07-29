<?php

namespace App\Actions\Categorization;

use App\Exceptions\StaleTransactionRevision;
use App\Models\AiCategoryProposal;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmAiCategoryProposal
{
    public function __construct(
        private CreateCategory $createCategory,
        private AssignCategoryToTransaction $assignCategoryToTransaction,
    ) {}

    public function handle(
        User $owner,
        int $transactionId,
        int $proposalId,
        int $expectedTransactionRevision,
        int $expectedProposalRevision,
    ): Category {
        return DB::transaction(function () use (
            $owner,
            $transactionId,
            $proposalId,
            $expectedTransactionRevision,
            $expectedProposalRevision,
        ): Category {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->revision !== $expectedTransactionRevision) {
                throw StaleTransactionRevision::fromTransaction($transaction);
            }

            $proposal = AiCategoryProposal::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereBelongsTo($transaction)
                ->whereKey($proposalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($proposal->revision !== $expectedProposalRevision || $proposal->confirmed_at !== null) {
                throw ValidationException::withMessages([
                    'expected_proposal_revision' => 'This Category proposal changed. Refresh and review it again.',
                ]);
            }

            $category = $this->createCategory->handle(
                owner: $owner,
                name: $proposal->name,
                parentId: $proposal->parent_id,
                description: $proposal->description,
                examples: $proposal->examples,
            );

            $this->assignCategoryToTransaction->handle(
                owner: $owner,
                transactionId: $transaction->id,
                expectedRevision: $expectedTransactionRevision,
                categoryId: $category->id,
                expectedCategoryRevision: $category->revision,
            );

            $proposal->forceFill([
                'revision' => $proposal->revision + 1,
                'confirmed_category_id' => $category->id,
                'confirmed_at' => now(),
            ])->save();

            return $category;
        }, 3);
    }
}
