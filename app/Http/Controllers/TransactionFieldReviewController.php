<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ResolveTransactionField;
use App\Http\Requests\ResolveTransactionFieldRequest;
use App\Models\Transaction;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use App\TransferPurpose;
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
            transferPurpose: isset($validated['transfer_purpose'])
                ? TransferPurpose::from($validated['transfer_purpose'])
                : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $resolution === TransactionFieldResolution::Accept
                ? __('Transaction detail accepted.')
                : __('Transaction updated.'),
        ]);

        if (isset($validated['next_review_item'])) {
            return to_route('review_queue.index', ['item' => $validated['next_review_item']]);
        }

        return $this->redirectToWorkspace('review_queue.index');
    }
}
