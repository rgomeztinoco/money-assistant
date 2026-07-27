<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\PreviewHistoricalLearnedRuleApplication;
use App\Http\Requests\PreviewHistoricalLearnedRuleApplicationRequest;
use App\Models\LearnedRule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LearnedRuleHistoricalApplicationController extends Controller
{
    public function __construct(private PreviewHistoricalLearnedRuleApplication $previewHistoricalLearnedRuleApplication) {}

    public function store(
        PreviewHistoricalLearnedRuleApplicationRequest $request,
        LearnedRule $learnedRule,
    ): RedirectResponse {
        $bulkAction = $this->previewHistoricalLearnedRuleApplication->handle(
            $request->user(),
            $learnedRule->id,
            (int) $request->validated('expected_revision'),
        );
        $items = [];

        foreach ($bulkAction->items()
            ->with(['transaction:id,merchant_description', 'previousCategory:id,name'])
            ->orderBy('transaction_id')
            ->lazy(200) as $item) {
            $items[] = [
                'transaction_id' => $item->transaction_id,
                'merchant_description' => $item->transaction->merchant_description,
                'expected_revision' => $item->expected_transaction_revision,
                'previous_category_name' => $item->previousCategory?->name,
            ];
        }

        Inertia::flash('historical_application_preview', [
            'id' => $bulkAction->id,
            'rule_id' => $bulkAction->learned_rule_id,
            'rule_revision' => $bulkAction->learned_rule_revision,
            'transaction_count' => count($items),
            'items' => $items,
        ]);

        return redirect()->route('learned_rules.index');
    }
}
