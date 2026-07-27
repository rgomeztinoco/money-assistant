<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\DismissLearnedRuleSuggestion;
use App\Http\Requests\DismissLearnedRuleSuggestionRequest;
use App\Models\LearnedRuleSuggestion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleSuggestionController extends Controller
{
    public function __construct(
        private DismissLearnedRuleSuggestion $dismissLearnedRuleSuggestion,
    ) {}

    public function destroy(
        DismissLearnedRuleSuggestionRequest $request,
        LearnedRuleSuggestion $learnedRuleSuggestion,
    ): RedirectResponse {
        $this->dismissLearnedRuleSuggestion->handle(
            $request->user(),
            $learnedRuleSuggestion->id,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Learned Rule suggestion dismissed.'),
        ]);

        return back();
    }
}
