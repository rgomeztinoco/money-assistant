<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\CreateCategory;
use App\Actions\Categorization\DeleteCategory;
use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Categorization\UpdateCategory;
use App\Actions\Retention\ReadFinancialTrash;
use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\StaleCategoryRevision;
use App\Http\Requests\ChangeCategoryRetirementRequest;
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
        private DeleteCategory $deleteCategory,
        private ReadFinancialTrash $readFinancialTrash,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('categories/index', [
            'categories' => $this->readCategoryTaxonomy->handle($request->user()),
            'trashed_categories' => $this->readFinancialTrash->categories($request->user()),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->createCategory->handle(
            owner: $request->user(),
            name: $validated['name'],
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('categories.index');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->updateCategory->handle(
                owner: $request->user(),
                categoryId: $category->id,
                expectedRevision: (int) $validated['expected_revision'],
                name: $validated['name'],
                parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            );
        } catch (StaleCategoryRevision $exception) {
            return back()->withErrors(['expected_revision' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category updated.')]);

        return to_route('categories.index');
    }

    public function destroy(ChangeCategoryRetirementRequest $request, Category $category): RedirectResponse
    {
        try {
            $this->deleteCategory->handle(
                $request->user(),
                $category->id,
                (int) $request->validated('expected_revision'),
            );
        } catch (StaleCategoryRevision $exception) {
            return back()->withErrors(['expected_revision' => $exception->getMessage()]);
        } catch (CategoryOperationBlocked $exception) {
            return back()->withErrors(['category' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category moved to trash for 30 days.')]);

        return to_route('categories.index');
    }
}
