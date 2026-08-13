<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\LinkRefundToPurchase;
use App\Http\Requests\LinkRefundToPurchaseRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class TransactionRefundLinkController extends Controller
{
    public function __construct(
        private LinkRefundToPurchase $linkRefundToPurchase,
    ) {}

    public function store(
        LinkRefundToPurchaseRequest $request,
        Transaction $refund,
    ): RedirectResponse {
        $validated = $request->validated();
        $purchase = Transaction::query()
            ->whereKey((int) $validated['purchase_id'])
            ->firstOrFail();

        try {
            $this->linkRefundToPurchase->handle(
                refund: $refund,
                purchase: $purchase,
            );
        } catch (InvalidArgumentException $exception) {
            Inertia::flash('refund_link_error', $exception->getMessage());

            return back()->withErrors([
                'refund_link' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Refund linked to its original purchase.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
