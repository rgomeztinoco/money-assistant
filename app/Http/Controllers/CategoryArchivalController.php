<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ArchiveCategory;
use App\Actions\Categorization\UnarchiveCategory;
use App\Http\Requests\ChangeCategoryArchivalRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryArchivalController extends Controller
{
    public function __construct(
        private ArchiveCategory $archiveCategory,
        private UnarchiveCategory $unarchiveCategory,
    ) {}

    public function store(ChangeCategoryArchivalRequest $request, Category $category): RedirectResponse
    {
        $this->archiveCategory->handle($request->user(), $category->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category archived.')]);

        return to_route('categories.index');
    }

    public function destroy(ChangeCategoryArchivalRequest $request, Category $category): RedirectResponse
    {
        $this->unarchiveCategory->handle($request->user(), $category->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category unarchived.')]);

        return to_route('categories.index');
    }
}
