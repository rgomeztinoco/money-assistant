<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\AcceptLearnedRuleSuggestion;
use App\Http\Requests\AcceptLearnedRuleSuggestionRequest;
use App\Models\LearnedRuleSuggestion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleSuggestionAcceptanceController extends Controller
{
    public function __construct(
        private AcceptLearnedRuleSuggestion $acceptLearnedRuleSuggestion,
    ) {}

    public function store(
        AcceptLearnedRuleSuggestionRequest $request,
        LearnedRuleSuggestion $learnedRuleSuggestion,
    ): RedirectResponse {
        $this->acceptLearnedRuleSuggestion->handle(
            $request->user(),
            $learnedRuleSuggestion->id,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Learned Rule activated.'),
        ]);

        return back();
    }
}
