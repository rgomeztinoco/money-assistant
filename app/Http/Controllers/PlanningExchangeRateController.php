<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePlanningExchangeRateRequest;
use App\Models\YearlyPaymentSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PlanningExchangeRateController extends Controller
{
    public function update(SavePlanningExchangeRateRequest $request): RedirectResponse
    {
        YearlyPaymentSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            ['pen_per_usd' => $request->validated('pen_per_usd')],
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planning exchange rate updated.']);

        return to_route('yearly_payments.index');
    }
}
