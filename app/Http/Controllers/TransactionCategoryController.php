<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\AssignCategoryToTransaction;
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

        $this->assignCategoryToTransaction->handle(
            transactionId: $transaction->id,
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => isset($validated['category_id'])
                ? __('Category assigned.')
                : __('Transaction returned to Uncategorized.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
