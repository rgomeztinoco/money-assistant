<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\ChangeReportingCurrency;
use App\Currency;
use App\Http\Requests\UpdateReportingCurrencyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReportingCurrencyController extends Controller
{
    public function __construct(private ChangeReportingCurrency $changeReportingCurrency) {}

    public function update(UpdateReportingCurrencyRequest $request): RedirectResponse
    {
        $this->changeReportingCurrency->handle(
            $request->user(),
            Currency::from($request->validated('reporting_currency')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reporting Currency updated.')]);

        return to_route('daily_exchange_rates.index');
    }
}
