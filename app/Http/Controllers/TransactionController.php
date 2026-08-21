<?php

namespace App\Http\Controllers;

use App\Actions\Categorization\ReadCategoryTaxonomy;
use App\Actions\Ledger\ReadLedger;
use App\Actions\Ledger\ReadTransactionInspector;
use App\Actions\Ledger\RecordManualTransaction;
use App\Actions\Ledger\UpdateTransaction;
use App\Currency;
use App\Http\Requests\IndexTransactionsRequest;
use App\Http\Requests\StoreManualTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Transaction;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(
        private RecordManualTransaction $recordManualTransaction,
        private ReadLedger $readLedger,
        private ReadCategoryTaxonomy $readCategoryTaxonomy,
        private ReadTransactionInspector $readTransactionInspector,
        private UpdateTransaction $updateTransaction,
    ) {}

    public function index(IndexTransactionsRequest $request): Response
    {
        $validated = $request->validated();

        return Inertia::render(
            'transactions/index',
            [
                ...$this->readLedger->handle($request->user(), $validated),
                'category_options' => $this->readCategoryTaxonomy->activeOptions($request->user()),
                'workspace' => ['mode' => 'transactions'],
                'selected_transaction_id' => isset($validated['selected'])
                    ? (int) $validated['selected']
                    : null,
                'selected_transaction' => isset($validated['selected'])
                    ? Inertia::defer(fn () => $this->readTransactionInspector->handle(
                        $request->user(),
                        (int) $validated['selected'],
                    ))
                    : null,
            ],
        );
    }

    public function store(StoreManualTransactionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->recordManualTransaction->handle(
            owner: $request->user(),
            occurredOn: CarbonImmutable::parse($validated['occurred_on'], config('app.timezone')),
            amountMinor: $request->amountMinor(),
            currency: Currency::from($validated['currency']),
            kind: TransactionKind::from($validated['kind']),
            merchantDescription: $validated['merchant_description'],
            paymentInstrumentLabel: $validated['payment_instrument_label'] ?? null,
            paymentInstrumentLastFour: $validated['payment_instrument_last_four'] ?? null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaction recorded.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $validated = $request->validated();

        $this->updateTransaction->handle(
            owner: $request->user(),
            transaction: $transaction,
            occurredOn: CarbonImmutable::parse($validated['occurred_on'], config('app.timezone')),
            amountMinor: $request->amountMinor(),
            currency: Currency::from($validated['currency']),
            kind: TransactionKind::from($validated['kind']),
            merchantDescription: $validated['merchant_description'],
            paymentInstrumentLabel: $validated['payment_instrument_label'] ?? null,
            paymentInstrumentLastFour: $validated['payment_instrument_last_four'] ?? null,
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            originalPurchaseId: isset($validated['original_purchase_id']) ? (int) $validated['original_purchase_id'] : null,
            removeReceiptBreakdown: (bool) ($validated['remove_receipt_breakdown'] ?? false),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaction updated.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }
}
