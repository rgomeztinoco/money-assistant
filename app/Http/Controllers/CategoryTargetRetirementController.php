<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\ReviseCategoryTarget;
use App\Http\Requests\RetireCategoryTargetRequest;
use App\Models\CategoryTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryTargetRetirementController extends Controller
{
    public function __construct(private ReviseCategoryTarget $reviseCategoryTarget) {}

    public function __invoke(
        RetireCategoryTargetRequest $request,
        CategoryTarget $categoryTarget,
    ): RedirectResponse {
        $this->reviseCategoryTarget->handle(
            owner: $request->user(),
            targetId: $categoryTarget->id,
            amountMinor: null,
            effectiveMonth: CarbonImmutable::parse($request->validated('effective_month')),
            expectedRevision: (int) $request->validated('expected_revision'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category Target retirement scheduled.'),
        ]);

        return back();
    }
}
