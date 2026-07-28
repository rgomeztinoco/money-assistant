<?php

namespace App\Http\Controllers;

use App\Actions\ReceiptReconciliation\ConfirmReceiptBreakdown;
use App\Exceptions\ReceiptBreakdownNotReconciled;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Http\Requests\ConfirmReceiptBreakdownRequest;
use App\Models\ReceiptBreakdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ReceiptBreakdownConfirmationController extends Controller
{
    public function __construct(private ConfirmReceiptBreakdown $confirmReceiptBreakdown) {}

    public function store(
        ConfirmReceiptBreakdownRequest $request,
        ReceiptBreakdown $receiptBreakdown,
    ): RedirectResponse {
        try {
            $this->confirmReceiptBreakdown->handle(
                $request->user(),
                $receiptBreakdown,
                $request->integer('expected_revision'),
            );
        } catch (StaleReceiptBreakdownRevision $exception) {
            throw ValidationException::withMessages([
                'expected_revision' => "The draft changed. Review revision {$exception->currentRevision} and try again.",
            ]);
        } catch (ReceiptBreakdownNotReconciled $exception) {
            throw ValidationException::withMessages([
                'reconciliation' => "The draft must reconcile exactly. Current delta: {$exception->deltaMinor} minor units.",
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Receipt Breakdown confirmed.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
