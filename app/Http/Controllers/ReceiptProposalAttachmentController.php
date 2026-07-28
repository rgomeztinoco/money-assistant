<?php

namespace App\Http\Controllers;

use App\Actions\ReceiptReconciliation\AttachReceiptProposalToTransaction;
use App\Http\Requests\AttachReceiptProposalRequest;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class ReceiptProposalAttachmentController extends Controller
{
    public function __construct(private AttachReceiptProposalToTransaction $attachReceiptProposal) {}

    public function store(
        AttachReceiptProposalRequest $request,
        Transaction $transaction,
    ): RedirectResponse {
        $this->attachReceiptProposal->handle(
            $request->user(),
            $transaction,
            $request->validated('receipt_proposal_id'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Receipt Proposal attached as a draft.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
