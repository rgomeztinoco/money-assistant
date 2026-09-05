<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\LinkRefundToSpending;
use App\Http\Requests\LinkRefundToSpendingRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class TransactionRefundLinkController extends Controller
{
    public function __construct(
        private LinkRefundToSpending $linkRefundToSpending,
    ) {}

    public function store(
        LinkRefundToSpendingRequest $request,
        Transaction $refund,
    ): RedirectResponse {
        $validated = $request->validated();
        $spending = Transaction::query()
            ->whereKey((int) $validated['spending_id'])
            ->firstOrFail();

        try {
            $this->linkRefundToSpending->handle(
                owner: $request->user(),
                refund: $refund,
                spending: $spending,
            );
        } catch (InvalidArgumentException $exception) {
            Inertia::flash('refund_link_error', $exception->getMessage());

            return back()->withErrors([
                'refund_link' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Refund linked to its original spending.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
