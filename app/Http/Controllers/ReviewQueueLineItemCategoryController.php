<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\AssignCategoryToLineItem;
use App\Http\Requests\AssignLineItemCategoryRequest;
use App\Models\LineItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReviewQueueLineItemCategoryController extends Controller
{
    public function __construct(private AssignCategoryToLineItem $assignCategoryToLineItem) {}

    public function update(
        AssignLineItemCategoryRequest $request,
        LineItem $lineItem,
    ): RedirectResponse {
        $validated = $request->validated();
        $this->assignCategoryToLineItem->handle(
            owner: $request->user(),
            lineItem: $lineItem,
            categoryId: (int) $validated['category_id'],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Line Item Category assigned. Moving to the next review.'),
        ]);

        if (isset($validated['next_review_item'])) {
            return to_route('review_queue.index', ['item' => $validated['next_review_item']]);
        }

        return $this->redirectToWorkspace('review_queue.index');
    }
}
