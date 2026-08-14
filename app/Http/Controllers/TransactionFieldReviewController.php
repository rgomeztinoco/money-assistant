<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ResolveTransactionField;
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

        $this->resolveTransactionField->handle(
            owner: $request->user(),
            transaction: $transaction,
            field: $field,
            resolution: $resolution,
            replacementValue: $validated['value'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $resolution === TransactionFieldResolution::Accept
                ? __('Transaction detail accepted.')
                : __('Transaction updated.'),
        ]);

        return $this->redirectToWorkspace('review_queue.index');
    }
}
