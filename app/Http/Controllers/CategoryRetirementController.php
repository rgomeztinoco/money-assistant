<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReactivateCategory;
use App\Actions\Categorization\RetireCategory;
use App\Exceptions\CategoryOperationBlocked;
use App\Exceptions\StaleCategoryRevision;
use App\Http\Requests\ChangeCategoryRetirementRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryRetirementController extends Controller
{
    public function __construct(
        private RetireCategory $retireCategory,
        private ReactivateCategory $reactivateCategory,
    ) {}

    public function store(ChangeCategoryRetirementRequest $request, Category $category): RedirectResponse
    {
        try {
            $this->retireCategory->handle(
                $request->user(),
                $category->id,
                (int) $request->validated('expected_revision'),
            );
        } catch (StaleCategoryRevision $exception) {
            return back()->withErrors(['expected_revision' => $exception->getMessage()]);
        } catch (CategoryOperationBlocked $exception) {
            return back()->withErrors(['category' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category retired.')]);

        return to_route('categories.index');
    }

    public function destroy(ChangeCategoryRetirementRequest $request, Category $category): RedirectResponse
    {
        try {
            $this->reactivateCategory->handle(
                $request->user(),
                $category->id,
                (int) $request->validated('expected_revision'),
            );
        } catch (StaleCategoryRevision $exception) {
            return back()->withErrors(['expected_revision' => $exception->getMessage()]);
        } catch (CategoryOperationBlocked $exception) {
            return back()->withErrors(['category' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category reactivated.')]);

        return to_route('categories.index');
    }
}
