<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\ReadSpendingInsights;
use App\Http\Requests\IndexInsightsRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController extends Controller
{
    public function __construct(private ReadSpendingInsights $readSpendingInsights) {}

    public function __invoke(IndexInsightsRequest $request): Response
    {
        $validated = $request->validated();
        $selectedMonth = isset($validated['date_from'])
            ? CarbonImmutable::parse($validated['date_from'], config('app.timezone'))->startOfMonth()
            : CarbonImmutable::today()->startOfMonth();

        return Inertia::render('insights/index', $this->readSpendingInsights->handle(
            owner: $request->user(),
            selectedMonth: $selectedMonth,
        ));
    }
}
