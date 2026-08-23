<?php

namespace App\Http\Controllers;

use App\Actions\Breakdown\ClassifyBreakdownTransaction;
use App\Http\Requests\UpdateBreakdownTransactionClassificationRequest;
use App\IncomeSource;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BreakdownTransactionClassificationController extends Controller
{
    public function update(
        UpdateBreakdownTransactionClassificationRequest $request,
        Transaction $transaction,
        ClassifyBreakdownTransaction $classifyBreakdownTransaction,
    ): RedirectResponse {
        $validated = $request->validated();
        $updatedCount = $classifyBreakdownTransaction->handle(
            owner: $request->user(),
            transaction: $transaction,
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            incomeSource: isset($validated['income_source'])
                ? IncomeSource::from($validated['income_source'])
                : null,
            applyToMatching: (bool) ($validated['apply_to_matching'] ?? false),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ($validated['apply_to_matching'] ?? false)
                ? trans_choice('{1} 1 matching Transaction updated; future exact matches will follow this Category.|[2,*] :count matching Transactions updated; future exact matches will follow this Category.', $updatedCount, ['count' => $updatedCount])
                : __('Classification updated.'),
        ]);

        return $this->redirectToWorkspace('breakdown.index');
    }
}
