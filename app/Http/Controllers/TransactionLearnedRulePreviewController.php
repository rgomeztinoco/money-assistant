<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\PreviewLearnedRuleFromCorrection;
use App\Http\Requests\PreviewLearnedRuleFromCorrectionRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TransactionLearnedRulePreviewController extends Controller
{
    public function __construct(private PreviewLearnedRuleFromCorrection $previewLearnedRuleFromCorrection) {}

    public function store(
        PreviewLearnedRuleFromCorrectionRequest $request,
        Transaction $transaction,
    ): RedirectResponse {
        $preview = $this->previewLearnedRuleFromCorrection->handle(
            $request->user(),
            $transaction->id,
            (int) $request->validated('expected_revision'),
        );

        Inertia::flash('rule_change_preview', [
            'id' => $preview->id,
            'learned_rule_id' => null,
            ...$preview->analysis,
        ]);

        return redirect()->route('learned_rules.index');
    }
}
