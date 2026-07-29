<?php

namespace App;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

final class AiClassificationTaxonomyFingerprint
{
    /** @param Collection<int, Category> $categories */
    public function make(Collection $categories): string
    {
        $guidanceByStableIdentity = $categories
            ->sortBy('id')
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'description' => $category->description,
                'examples' => $category->examples,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($guidanceByStableIdentity, JSON_THROW_ON_ERROR));
    }
}
