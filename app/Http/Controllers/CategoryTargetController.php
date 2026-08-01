<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\CreateCategoryTarget;
use App\Actions\Reporting\ReviseCategoryTarget;
use App\Currency;
use App\Http\Requests\ReviseCategoryTargetRequest;
use App\Http\Requests\StoreCategoryTargetRequest;
use App\Models\CategoryTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryTargetController extends Controller
{
    public function __construct(
        private CreateCategoryTarget $createCategoryTarget,
        private ReviseCategoryTarget $reviseCategoryTarget,
    ) {}

    public function store(StoreCategoryTargetRequest $request): RedirectResponse
    {
        $this->createCategoryTarget->handle(
            owner: $request->user(),
            categoryId: (int) $request->validated('category_id'),
            amountMinor: (string) $request->validated('amount_minor'),
            currency: Currency::from($request->validated('currency')),
            startsOn: CarbonImmutable::parse($request->validated('starts_on')),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category Target activated.'),
        ]);

        return back();
    }

    public function update(ReviseCategoryTargetRequest $request, CategoryTarget $categoryTarget): RedirectResponse
    {
        $this->reviseCategoryTarget->handle(
            owner: $request->user(),
            targetId: $categoryTarget->id,
            amountMinor: (string) $request->validated('amount_minor'),
            effectiveMonth: CarbonImmutable::parse($request->validated('effective_month')),
            expectedRevision: (int) $request->validated('expected_revision'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category Target revised.'),
        ]);

        return back();
    }
}
