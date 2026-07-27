<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReactivateLearnedRule;
use App\Actions\Categorization\RetireLearnedRule;
use App\Http\Requests\ChangeLearnedRuleLifecycleRequest;
use App\Models\LearnedRule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleRetirementController extends Controller
{
    public function __construct(
        private RetireLearnedRule $retireLearnedRule,
        private ReactivateLearnedRule $reactivateLearnedRule,
    ) {}

    public function store(ChangeLearnedRuleLifecycleRequest $request, LearnedRule $learnedRule): RedirectResponse
    {
        $this->retireLearnedRule->handle($request->user(), $learnedRule->id, (int) $request->validated('expected_revision'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learned Rule retired.')]);

        return back();
    }

    public function destroy(ChangeLearnedRuleLifecycleRequest $request, LearnedRule $learnedRule): RedirectResponse
    {
        $this->reactivateLearnedRule->handle($request->user(), $learnedRule->id, (int) $request->validated('expected_revision'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learned Rule reactivated.')]);

        return back();
    }
}
