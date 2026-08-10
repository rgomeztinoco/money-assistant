<?php

namespace App\Actions\Retention;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreDeletedCategory
{
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

            return $category;
        }, 3);
    }
}
