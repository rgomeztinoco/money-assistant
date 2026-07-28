<?php

namespace App\Http\Controllers;

use App\Actions\ReceiptReconciliation\UpdateReceiptBreakdownDraft;
use App\Exceptions\StaleReceiptBreakdownRevision;
use App\Http\Requests\UpdateReceiptBreakdownRequest;
use App\Models\ReceiptBreakdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ReceiptBreakdownController extends Controller
{
    public function __construct(private UpdateReceiptBreakdownDraft $updateDraft) {}

    public function update(
        UpdateReceiptBreakdownRequest $request,
        ReceiptBreakdown $receiptBreakdown,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $this->updateDraft->handle(
                $request->user(),
                $receiptBreakdown,
                (int) $validated['expected_revision'],
                $request->lineItems(),
            );
        } catch (StaleReceiptBreakdownRevision $exception) {
            throw ValidationException::withMessages([
                'expected_revision' => "The draft changed. Review revision {$exception->currentRevision} and try again.",
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Receipt Breakdown draft saved.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
