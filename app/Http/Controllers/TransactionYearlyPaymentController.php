<?php

namespace App\Http\Controllers;

use App\Actions\YearlyPayments\ReadYearlyPaymentPlan;
use App\Http\Requests\LinkTransactionYearlyPaymentRequest;
use App\Models\Transaction;
use App\Models\YearlyPayment;
use App\TransactionKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TransactionYearlyPaymentController extends Controller
{
    public function show(Request $request, Transaction $transaction, ReadYearlyPaymentPlan $plan): JsonResponse
    {
        abort_unless($transaction->user_id === $request->user()->getKey(), 403);
        abort_unless($transaction->kind === TransactionKind::Spending && $transaction->voided_at === null, 403);

        return response()->json([
            'commitments' => $plan->handle($request->user())['commitments'],
            'yearly_payment_id' => $transaction->yearly_payment_id,
        ]);
    }

    public function store(LinkTransactionYearlyPaymentRequest $request, Transaction $transaction): RedirectResponse
    {
        DB::transaction(function () use ($request, $transaction): void {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->voided_at !== null || $transaction->kind !== TransactionKind::Spending) {
                throw ValidationException::withMessages(['yearly_payment_id' => 'Choose an active Spending Transaction.']);
            }

            if ($transaction->yearly_payment_id !== null) {
                throw ValidationException::withMessages(['yearly_payment_id' => 'This Transaction is already linked to a yearly payment.']);
            }

            $payment = $request->filled('yearly_payment_id')
                ? YearlyPayment::query()->whereBelongsTo($request->user(), 'owner')->findOrFail($request->integer('yearly_payment_id'))
                : YearlyPayment::query()->create(['user_id' => $request->user()->getKey(), ...$request->paymentAttributes()]);

            $transaction->yearlyPayment()->associate($payment);
            $transaction->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Yearly payment linked.']);

        return $this->redirectToWorkspace('yearly_payments.index');
    }
}
