<?php

namespace App\Actions\Retention;

use App\Actions\Categorization\InvalidateAiClassificationValidationContext;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreDeletedCategory
{
    public function __construct(
        private InvalidateAiClassificationValidationContext $invalidateValidationContext,
    ) {}

    public function handle(User $owner, string $deletionId): Category
    {
        return DB::transaction(function () use ($owner, $deletionId): Category {
            $category = Category::query()
                ->restorableTrash()
                ->whereBelongsTo($owner, 'owner')
                ->where('deletion_id', $deletionId)
                ->lockForUpdate()
                ->firstOrFail();

            $category->deletion_id = null;
            $category->purge_after = null;
            $category->restore();

            $this->invalidateValidationContext->handle($owner);

            return $category;
        }, 3);
    }
}
