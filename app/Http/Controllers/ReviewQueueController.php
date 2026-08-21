<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Ledger\ReadFocusedReviewQueue;
use App\Actions\Ledger\ReadLedger;
use App\Actions\Ledger\ReadReviewQueue;
use App\Actions\Ledger\ReadTransactionInspector;
use App\Http\Requests\IndexTransactionsRequest;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class ReviewQueueController extends Controller
{
    public function __construct(
        private ReadLedger $readLedger,
        private ReadFocusedReviewQueue $readFocusedReviewQueue,
        private ReadReviewQueue $readReviewQueue,
        private ReadTransactionInspector $readTransactionInspector,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
    ) {}

    public function __invoke(IndexTransactionsRequest $request): Response
    {
        $validated = $request->validated();
        $filters = [
            ...$validated,
            'review_state' => 'outstanding',
            'void_state' => 'active',
        ];
        $ledger = $this->readLedger->handle($request->user(), $filters);
        $reviewQueue = Arr::except(
            $this->readReviewQueue->handle($request->user()),
            ['transactions'],
        );
        $focusedQueue = $this->readFocusedReviewQueue->handle($request->user());
        $itemKeys = collect($focusedQueue['items'])->pluck('key')->values();
        $requestedItemKey = $validated['item'] ?? null;
        $currentItemKey = is_string($requestedItemKey) && $itemKeys->containsStrict($requestedItemKey)
            ? $requestedItemKey
            : $itemKeys->first();
        $currentPosition = is_string($currentItemKey)
            ? $itemKeys->search($currentItemKey, strict: true) + 1
            : 0;
        $selectedTransactionId = isset($validated['selected']) ? (int) $validated['selected'] : null;

        if ($selectedTransactionId === null && ($validated['inspector'] ?? null) !== 'closed') {
            $selectedTransactionId = data_get($ledger, 'transactions.0.id');
        }

        return Inertia::render(
            'review-queue/index',
            [
                ...$ledger,
                ...$reviewQueue,
                'queue' => [
                    ...$focusedQueue,
                    'current_item_key' => $currentItemKey,
                    'current_position' => $currentPosition,
                    'view' => $validated['view'] ?? 'guided',
                ],
                'category_options' => $this->readCategoryTaxonomy->activeOptions($request->user()),
                'workspace' => ['mode' => 'review_queue'],
                'stale_transaction' => $request->session()->get('stale_transaction'),
                'inspector_open' => isset($validated['selected']),
                'selected_transaction_id' => $selectedTransactionId,
                'selected_transaction' => is_int($selectedTransactionId)
                    ? Inertia::defer(fn () => $this->readTransactionInspector->handle(
                        $request->user(),
                        $selectedTransactionId,
                    ))
                    : null,
            ],
        );
    }
}
