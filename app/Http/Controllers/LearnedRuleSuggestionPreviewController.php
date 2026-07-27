<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\PreviewLearnedRuleSuggestion;
use App\Http\Requests\PreviewLearnedRuleSuggestionRequest;
use App\Models\LearnedRuleSuggestion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleSuggestionPreviewController extends Controller
{
    public function __construct(private PreviewLearnedRuleSuggestion $previewLearnedRuleSuggestion) {}

    public function store(
        PreviewLearnedRuleSuggestionRequest $request,
        LearnedRuleSuggestion $learnedRuleSuggestion,
    ): RedirectResponse {
        $preview = $this->previewLearnedRuleSuggestion->handle(
            $request->user(),
            $learnedRuleSuggestion->id,
        );

        Inertia::flash('rule_change_preview', [
            'id' => $preview->id,
            'learned_rule_id' => null,
            ...$preview->analysis,
        ]);

        return redirect()->route('learned_rules.index');
    }
}
