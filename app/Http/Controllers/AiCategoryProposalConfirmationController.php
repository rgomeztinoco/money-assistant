<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ConfirmAiCategoryProposal;
use App\Exceptions\StaleTransactionRevision;
use App\Http\Requests\ConfirmAiCategoryProposalRequest;
use App\Models\AiCategoryProposal;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AiCategoryProposalConfirmationController extends Controller
{
    public function __construct(
        private ConfirmAiCategoryProposal $confirmAiCategoryProposal,
    ) {}

    public function store(
        ConfirmAiCategoryProposalRequest $request,
        Transaction $transaction,
        AiCategoryProposal $aiCategoryProposal,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $this->confirmAiCategoryProposal->handle(
                owner: $request->user(),
                transactionId: $transaction->id,
                proposalId: $aiCategoryProposal->id,
                expectedTransactionRevision: (int) $validated['expected_transaction_revision'],
                expectedProposalRevision: (int) $validated['expected_proposal_revision'],
            );
        } catch (StaleTransactionRevision $exception) {
            return back()->withErrors([
                'expected_transaction_revision' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Category created and assigned to this Transaction.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
