<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateCategory
{
    public function handle(
        string $name,
        ?int $parentId,
    ): Category {
        $name = Str::squish($name);

        try {
            return DB::transaction(function () use ($name, $parentId): Category {
                $parent = $this->activeParent($parentId);

                $this->ensureNameAvailable($name, $parent?->id);

                $category = Category::query()->create([
                    'parent_id' => $parent?->id,
                    'name' => $name,
                ]);

                return $category;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'name' => 'An active sibling Category already uses this name.',
            ]);
        }
    }

    private function activeParent(?int $parentId): ?Category
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Category::query()
            ->whereKey($parentId)
            ->whereNull('parent_id')
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose an active top-level Category.',
            ]);
        }

        return $parent;
    }

    private function ensureNameAvailable(string $name, ?int $parentId): void
    {
        $exists = Category::query()
            ->whereNull('archived_at')
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'An active sibling Category already uses this name.',
            ]);
        }
    }
}
