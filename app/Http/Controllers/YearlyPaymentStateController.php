<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeYearlyPaymentStateRequest;
use App\Models\YearlyPayment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class YearlyPaymentStateController extends Controller
{
    public function update(ChangeYearlyPaymentStateRequest $request, YearlyPayment $yearlyPayment): RedirectResponse
    {
        $yearlyPayment->update(['is_active' => $request->boolean('is_active')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $yearlyPayment->is_active ? 'Yearly payment resumed.' : 'Yearly payment paused.']);

        return to_route('yearly_payments.index');
    }
}
