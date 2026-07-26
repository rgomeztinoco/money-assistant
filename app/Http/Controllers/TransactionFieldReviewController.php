<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ResolveTransactionField;
use App\Exceptions\StaleTransactionRevision;
use App\Http\Requests\ResolveTransactionFieldRequest;
use App\Models\Transaction;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TransactionFieldReviewController extends Controller
{
    public function __construct(private ResolveTransactionField $resolveTransactionField) {}

    public function update(
        ResolveTransactionFieldRequest $request,
        Transaction $transaction,
        ReviewableTransactionField $field,
    ): RedirectResponse {
        $validated = $request->validated();
        $resolution = TransactionFieldResolution::from($validated['resolution']);

        try {
            $this->resolveTransactionField->handle(
                owner: $request->user(),
                transaction: $transaction,
                field: $field,
                expectedRevision: (int) $validated['expected_revision'],
                resolution: $resolution,
                correctedValue: $validated['value'] ?? null,
            );
        } catch (StaleTransactionRevision $exception) {
            return back()->withErrors([
                'expected_revision' => $exception->getMessage(),
            ])->with('stale_transaction', $exception->currentState());
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $resolution === TransactionFieldResolution::Accept
                ? __('Transaction detail accepted.')
                : __('Correction saved.'),
        ]);

        return $this->redirectToWorkspace('review_queue.index');
    }
}
