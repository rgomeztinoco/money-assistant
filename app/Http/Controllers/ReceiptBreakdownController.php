<?php

namespace App\Http\Controllers;

use App\Actions\ReceiptReconciliation\RemoveReceiptBreakdown;
use App\Actions\ReceiptReconciliation\SaveReceiptBreakdown;
use App\Http\Requests\DeleteReceiptBreakdownRequest;
use App\Http\Requests\UpdateReceiptBreakdownRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class ReceiptBreakdownController extends Controller
{
    public function update(
        UpdateReceiptBreakdownRequest $request,
        Transaction $transaction,
        SaveReceiptBreakdown $saveReceiptBreakdown,
    ): RedirectResponse {
        $saveReceiptBreakdown->handle($request->user(), $transaction, $request->lineItems());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Receipt Breakdown saved.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }

    public function destroy(
        DeleteReceiptBreakdownRequest $request,
        Transaction $transaction,
        RemoveReceiptBreakdown $removeReceiptBreakdown,
    ): RedirectResponse {
        $removeReceiptBreakdown->handle($request->user(), $transaction);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Receipt Breakdown removed.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
