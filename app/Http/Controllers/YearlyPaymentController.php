<?php

namespace App\Http\Controllers;

use App\Actions\YearlyPayments\ReadYearlyPaymentPlan;
use App\Http\Requests\SaveYearlyPaymentRequest;
use App\Models\YearlyPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class YearlyPaymentController extends Controller
{
    public function index(Request $request, ReadYearlyPaymentPlan $plan): Response
    {
        return Inertia::render('yearly-payments/index', ['plan' => $plan->handle($request->user())]);
    }

    public function store(SaveYearlyPaymentRequest $request): RedirectResponse
    {
        YearlyPayment::query()->create(['user_id' => $request->user()->getKey(), ...$request->paymentAttributes()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Yearly payment added.']);

        return $this->redirectToWorkspace('yearly_payments.index');
    }

    public function update(SaveYearlyPaymentRequest $request, YearlyPayment $yearlyPayment): RedirectResponse
    {
        $yearlyPayment->update($request->paymentAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Yearly payment updated.']);

        return $this->redirectToWorkspace('yearly_payments.index');
    }
}
