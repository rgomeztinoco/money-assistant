<?php

namespace App\Actions\Categorization;

use App\Exceptions\CategoryOperationBlocked;
use App\Models\Category;

final class EnsureCategoryCanBeDeleted
{
    public function handle(Category $category): void
    {
        if ($category->transactions()->exists()
            || $category->assignments()->exists()
            || $category->previousAssignments()->exists()
            || $category->lineItems()->exists()) {
            throw new CategoryOperationBlocked('This Category has historical Transaction or Line Item assignments and must be retired instead.');
        }

        if ($category->merchantRules()->exists()) {
            throw new CategoryOperationBlocked('Delete or retarget every Merchant Rule using this Category first.');
        }

        if ($category->targets()->exists()) {
            throw new CategoryOperationBlocked('This Category has historical financial planning activity and must be retired instead.');
        }

        if ($category->children()->withTrashed()->exists()) {
            throw new CategoryOperationBlocked('Move or delete every child Category first.');
        }
    }
}
