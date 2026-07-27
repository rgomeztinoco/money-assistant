<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\UndoHistoricalLearnedRuleApplication;
use App\Http\Requests\ManageLearnedRuleBulkActionRequest;
use App\Models\LearnedRuleBulkAction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleBulkActionController extends Controller
{
    public function __construct(private UndoHistoricalLearnedRuleApplication $undoHistoricalLearnedRuleApplication) {}

    public function destroy(ManageLearnedRuleBulkActionRequest $request, LearnedRuleBulkAction $learnedRuleBulkAction): RedirectResponse
    {
        $bulkAction = $this->undoHistoricalLearnedRuleApplication->handle($request->user(), $learnedRuleBulkAction->id);
        $restored = $bulkAction->items()->where('status', 'restored')->count();
        $skipped = $bulkAction->items()->where('status', 'skipped')->count();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Historical application undone: :restored restored, :skipped skipped.', compact('restored', 'skipped')),
        ]);

        return back();
    }
}
