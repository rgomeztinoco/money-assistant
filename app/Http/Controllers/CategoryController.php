<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\CreateCategory;
use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Categorization\UpdateCategory;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
        private CreateCategory $createCategory,
        private UpdateCategory $updateCategory,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('categories/index', [
            'categories' => $this->readCategoryTaxonomy->handle(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->createCategory->handle(
            name: $validated['name'],
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('categories.index');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $validated = $request->validated();

        $this->updateCategory->handle(
            categoryId: $category->id,
            name: $validated['name'],
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category updated.')]);

        return to_route('categories.index');
    }
}
