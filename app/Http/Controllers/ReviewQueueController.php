<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ReadLedger;
use App\Actions\Ledger\ReadReviewQueue;
use App\Actions\Ledger\ReadTransactionInspector;
use App\Http\Requests\IndexTransactionsRequest;
use Inertia\Inertia;
use Inertia\Response;

class ReviewQueueController extends Controller
{
    public function __construct(
        private ReadLedger $readLedger,
        private ReadReviewQueue $readReviewQueue,
        private ReadTransactionInspector $readTransactionInspector,
    ) {}

    public function __invoke(IndexTransactionsRequest $request): Response
    {
        $reviewQueue = $this->readReviewQueue->handle($request->user());
        $validated = $request->validated();
        $filters = [
            ...$validated,
            'review_state' => 'outstanding',
            'void_state' => 'active',
        ];
        $selectedTransactionId = isset($validated['selected'])
            ? (int) $validated['selected']
            : null;
        $ledger = $this->readLedger->handle(
            owner: $request->user(),
            filters: $filters,
            selectedTransactionId: $selectedTransactionId,
            includeEveryMatch: true,
        );

        if ($selectedTransactionId === null && ($validated['inspector'] ?? null) !== 'closed') {
            $selectedTransactionId = data_get($ledger, 'transactions.0.id');
            $ledger['selected_transaction'] = $this->readTransactionInspector->handle(
                $request->user(),
                is_int($selectedTransactionId) ? $selectedTransactionId : null,
            );
        }

        return Inertia::render(
            'review-queue/index',
            [
                ...$reviewQueue,
                ...$ledger,
                'workspace_transactions' => $ledger['transactions'],
                'workspace_voided_transactions' => $ledger['voided_transactions'],
                'transactions' => $reviewQueue['transactions'],
                'workspace' => ['mode' => 'review_queue'],
                'stale_transaction' => $request->session()->get('stale_transaction'),
            ],
        );
    }
}
