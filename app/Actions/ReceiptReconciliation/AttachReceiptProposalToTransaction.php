<?php

namespace App\Actions\ReceiptReconciliation;

use App\LineItemRole;
use App\Models\ReceiptBreakdown;
use App\Models\ReceiptProposal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AttachReceiptProposalToTransaction
{
    public function handle(User $owner, Transaction $transaction, string $proposalId): ReceiptBreakdown
    {
        return DB::transaction(function () use ($owner, $transaction, $proposalId): ReceiptBreakdown {
            $currentTransaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $proposal = ReceiptProposal::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('proposal_id', $proposalId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureProposalCanAttach($currentTransaction, $proposal);

            $breakdown = ReceiptBreakdown::query()->create([
                'user_id' => $owner->getKey(),
                'transaction_id' => $currentTransaction->getKey(),
                'receipt_proposal_id' => $proposal->getKey(),
                'status' => 'draft',
                'revision' => 1,
            ]);

            foreach ($proposal->proposed_line_items as $lineItem) {
                $role = LineItemRole::tryFrom($lineItem['role'] ?? 'purchased_item');

                if ($role === null) {
                    throw ValidationException::withMessages([
                        'receipt_proposal_id' => 'The Receipt Proposal contains an invalid Line Item role.',
                    ]);
                }

                $breakdown->lineItems()->create([
                    'line_item_id' => (string) Str::uuid(),
                    'description' => $lineItem['description'],
                    'role' => $role,
                    'quantity' => $lineItem['quantity'] ?? null,
                    'unit_price_minor' => $lineItem['unit_price_minor'] ?? null,
                    'line_total_minor' => $lineItem['line_total_minor'],
                ]);
            }

            return $breakdown->load('lineItems');
        }, 3);
    }

    private function ensureProposalCanAttach(Transaction $transaction, ReceiptProposal $proposal): void
    {
        if ($transaction->voided_at !== null) {
            throw ValidationException::withMessages([
                'receipt_proposal_id' => 'A Receipt Proposal cannot attach to a Voided Transaction.',
            ]);
        }

        if ($transaction->receiptBreakdowns()->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages([
                'receipt_proposal_id' => 'This Transaction already has a draft Receipt Breakdown.',
            ]);
        }

        if ($proposal->receiptBreakdown()->exists()) {
            throw ValidationException::withMessages([
                'receipt_proposal_id' => 'This Receipt Proposal is already attached.',
            ]);
        }

        $proposedTransaction = $proposal->proposed_transaction;

        if ($proposedTransaction['currency'] !== $transaction->currency->value
            || $proposedTransaction['kind'] !== $transaction->kind->value) {
            throw ValidationException::withMessages([
                'receipt_proposal_id' => 'Choose a Transaction with the Receipt Proposal currency and kind.',
            ]);
        }

        foreach ($proposal->proposed_line_items as $lineItem) {
            $role = LineItemRole::tryFrom($lineItem['role'] ?? 'purchased_item');

            if ($role === null
                || $role === LineItemRole::Unidentified
                || Str::squish($lineItem['description']) === ''
                || ! $role->acceptsLineTotal($lineItem['line_total_minor'])) {
                throw ValidationException::withMessages([
                    'receipt_proposal_id' => 'The Receipt Proposal contains an invalid Line Item.',
                ]);
            }
        }
    }
}
