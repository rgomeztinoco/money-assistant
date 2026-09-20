<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\CreateCategory;
use App\Http\Requests\StoreCategoryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class InlineCategoryController extends Controller
{
    public function __construct(private CreateCategory $createCategory) {}

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $category = $this->createCategory->handle(
            owner: $request->user(),
            name: $validated['name'],
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );
        $category->load('parent:id,name');

        Inertia::flash([
            'created_category' => [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'path' => $category->parent === null
                    ? $category->name
                    : $category->parent->name.' > '.$category->name,
            ],
            'toast' => ['type' => 'success', 'message' => __('Category created.')],
        ]);

        return back();
    }
}
