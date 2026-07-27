<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\CreateLearnedRuleFromCorrection;
use App\Actions\Categorization\ReadLearnedRules;
use App\Http\Requests\StoreLearnedRuleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearnedRuleController extends Controller
{
    public function __construct(
        private CreateLearnedRuleFromCorrection $createLearnedRuleFromCorrection,
        private ReadLearnedRules $readLearnedRules,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('learned-rules/index', $this->readLearnedRules->handle($request->user()));
    }

    public function store(StoreLearnedRuleRequest $request): RedirectResponse
    {
        $this->createLearnedRuleFromCorrection->handle(
            $request->user(),
            (int) $request->validated('transaction_id'),
            (int) $request->validated('expected_revision'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Learned Rule activated.'),
        ]);

        return back();
    }
}
