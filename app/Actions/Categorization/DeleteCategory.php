<?php

namespace App\Actions\Categorization;

use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\StaleCategoryRevision;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteCategory
{
    public function __construct(
        private InvalidateAiClassificationValidationContext $invalidateValidationContext,
    ) {}

    public function handle(User $owner, int $categoryId, int $expectedRevision): void
    {
        DB::transaction(function () use ($owner, $categoryId, $expectedRevision): void {
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($categoryId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($category->revision !== $expectedRevision) {
                throw new StaleCategoryRevision;
            }

            if ($category->transactions()->exists()
                || $category->assignments()->exists()
                || $category->previousAssignments()->exists()
                || $category->lineItems()->exists()) {
                throw new CategoryOperationBlocked('This Category has historical Transaction or Line Item assignments and must be retired instead.');
            }

            if ($category->learnedRuleRevisions()->exists()
                || $category->learnedRuleSuggestions()->exists()
                || $category->learnedRuleBulkActionItems()->exists()) {
                throw new CategoryOperationBlocked('This Category has historical Learned Rule activity and must be retired instead.');
            }

            if ($category->targets()->exists()
                || $category->proposedChildren()->exists()
                || $category->confirmedAiProposals()->exists()) {
                throw new CategoryOperationBlocked('This Category has historical financial planning or classification activity and must be retired instead.');
            }

            if ($category->children()->withTrashed()->exists()) {
                throw new CategoryOperationBlocked('Move or delete every child Category first.');
            }

            $category->moveToFinancialTrash();
            $this->invalidateValidationContext->handle($owner);
        }, 3);
    }
}
