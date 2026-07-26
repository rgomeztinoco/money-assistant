<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\AssignCategoryToTransaction;
use App\Exceptions\StaleTransactionRevision;
use App\Http\Requests\AssignTransactionCategoryRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TransactionCategoryController extends Controller
{
    public function __construct(
        private AssignCategoryToTransaction $assignCategoryToTransaction,
    ) {}

    public function update(
        AssignTransactionCategoryRequest $request,
        Transaction $transaction,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $this->assignCategoryToTransaction->handle(
                owner: $request->user(),
                transactionId: $transaction->id,
                expectedRevision: (int) $validated['expected_revision'],
                categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            );
        } catch (StaleTransactionRevision $exception) {
            return back()->withErrors(['expected_revision' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => isset($validated['category_id'])
                ? __('Category assigned.')
                : __('Transaction returned to Uncategorized.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
