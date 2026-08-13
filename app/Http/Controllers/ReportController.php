<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\ReadCurrencyReport;
use App\Currency;
use App\Http\Requests\IndexReportRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(private ReadCurrencyReport $readCurrencyReport) {}

    public function __invoke(IndexReportRequest $request, Currency $currency): Response
    {
        $validated = $request->validated();
        $dateTo = isset($validated['date_to'])
            ? CarbonImmutable::parse($validated['date_to'], config('app.timezone'))
            : CarbonImmutable::today();
        $dateFrom = isset($validated['date_from'])
            ? CarbonImmutable::parse($validated['date_from'], config('app.timezone'))
            : $dateTo->startOfMonth();

        return Inertia::render('reports/show', $this->readCurrencyReport->handle(
            currency: $currency,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        ));
    }
}
