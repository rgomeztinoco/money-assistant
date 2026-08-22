<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\CategorizeReviewTransaction;
use App\Currency;
use App\Http\Requests\CategorizeReviewTransactionRequest;
use App\Models\Transaction;
use App\TransactionKind;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReviewQueueTransactionCategoryController extends Controller
{
    public function __construct(
        private CategorizeReviewTransaction $categorizeReviewTransaction,
    ) {}

    public function update(
        CategorizeReviewTransactionRequest $request,
        Transaction $transaction,
    ): RedirectResponse {
        $validated = $request->validated();
        $result = $this->categorizeReviewTransaction->handle(
            owner: $request->user(),
            transaction: $transaction,
            categoryId: (int) $validated['category_id'],
            createMerchantRule: (bool) $validated['create_merchant_rule'],
            bulkAssign: (bool) $validated['bulk_assign'],
            ruleTransactionKind: isset($validated['rule_transaction_kind'])
                ? TransactionKind::from($validated['rule_transaction_kind'])
                : null,
            ruleCurrency: isset($validated['rule_currency'])
                ? Currency::from($validated['rule_currency'])
                : null,
        );

        $message = $result['assigned_transaction_count'] === 1
            ? __('Category assigned. Moving to the next review.')
            : __('Category assigned to :count matching current Transactions.', [
                'count' => $result['assigned_transaction_count'],
            ]);

        if ($result['merchant_rule_created']) {
            $message .= ' '.__('Future matching Transactions will use the new Merchant Rule.');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        if (isset($validated['next_review_item'])) {
            return to_route('review_queue.index', ['item' => $validated['next_review_item']]);
        }

        return $this->redirectToWorkspace('review_queue.index');
    }
}
