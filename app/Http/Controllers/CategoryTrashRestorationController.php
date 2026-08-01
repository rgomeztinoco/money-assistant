<?php

namespace App\Http\Controllers;

use App\Actions\Retention\RestoreDeletedCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryTrashRestorationController extends Controller
{
    public function __construct(
        private RestoreDeletedCategory $restoreDeletedCategory,
    ) {}

    public function __invoke(Request $request, string $deletionId): RedirectResponse
    {
        $this->restoreDeletedCategory->handle($request->user(), $deletionId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category restored from trash.')]);

        return back();
    }
}
