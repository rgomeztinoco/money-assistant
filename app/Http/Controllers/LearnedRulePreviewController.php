<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\CreateLearnedRuleChangePreview;
use App\Http\Requests\StoreLearnedRulePreviewRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRulePreviewController extends Controller
{
    public function __construct(private CreateLearnedRuleChangePreview $createLearnedRuleChangePreview) {}

    public function store(StoreLearnedRulePreviewRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $preview = $this->createLearnedRuleChangePreview->handle($request->user(), [
            'learned_rule_id' => isset($validated['learned_rule_id']) ? (int) $validated['learned_rule_id'] : null,
            'expected_revision' => isset($validated['expected_revision']) ? (int) $validated['expected_revision'] : null,
            'category_id' => (int) $validated['category_id'],
            'merchant_pattern' => (string) $validated['merchant_pattern'],
            'match_mode' => (string) $validated['match_mode'],
            'transaction_kind' => isset($validated['transaction_kind']) ? (string) $validated['transaction_kind'] : null,
            'currency' => isset($validated['currency']) ? (string) $validated['currency'] : null,
            'payment_instrument_label' => isset($validated['payment_instrument_label']) ? (string) $validated['payment_instrument_label'] : null,
            'payment_instrument_last_four' => isset($validated['payment_instrument_last_four']) ? (string) $validated['payment_instrument_last_four'] : null,
        ]);

        Inertia::flash('rule_change_preview', [
            'id' => $preview->id,
            'learned_rule_id' => $preview->learned_rule_id,
            ...$preview->analysis,
        ]);

        return redirect()->route('learned_rules.index');
    }
}
