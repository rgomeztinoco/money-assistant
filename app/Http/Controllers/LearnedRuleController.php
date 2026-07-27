<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ConfirmLearnedRuleChange;
use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Categorization\ReadLearnedRules;
use App\Http\Requests\ConfirmLearnedRuleChangeRequest;
use App\Http\Requests\StoreLearnedRuleRequest;
use App\Models\LearnedRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearnedRuleController extends Controller
{
    public function __construct(
        private ConfirmLearnedRuleChange $confirmLearnedRuleChange,
        private ReadLearnedRules $readLearnedRules,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('learned-rules/index', [
            ...$this->readLearnedRules->handle($request->user()),
            'category_options' => $this->readCategoryTaxonomy->activeOptions($request->user()),
        ]);
    }

    public function store(StoreLearnedRuleRequest $request): RedirectResponse
    {
        $this->confirmLearnedRuleChange->handle(
            $request->user(),
            (int) $request->validated('preview_id'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Learned Rule activated.'),
        ]);

        return back();
    }

    public function update(ConfirmLearnedRuleChangeRequest $request, LearnedRule $learnedRule): RedirectResponse
    {
        $this->confirmLearnedRuleChange->handle(
            $request->user(),
            (int) $request->validated('preview_id'),
            $learnedRule->id,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Learned Rule revised.'),
        ]);

        return back();
    }
}
