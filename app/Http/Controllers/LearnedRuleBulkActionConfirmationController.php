<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ConfirmHistoricalLearnedRuleApplication;
use App\Http\Requests\ManageLearnedRuleBulkActionRequest;
use App\Models\LearnedRuleBulkAction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleBulkActionConfirmationController extends Controller
{
    public function __construct(private ConfirmHistoricalLearnedRuleApplication $confirmHistoricalLearnedRuleApplication) {}

    public function store(ManageLearnedRuleBulkActionRequest $request, LearnedRuleBulkAction $learnedRuleBulkAction): RedirectResponse
    {
        $this->confirmHistoricalLearnedRuleApplication->handle($request->user(), $learnedRuleBulkAction->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Historical Learned Rule application completed.')]);

        return back();
    }
}
