<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ReadLedger;
use App\Actions\Ledger\RecordManualTransaction;
use App\Currency;
use App\Http\Requests\StoreManualTransactionRequest;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(
        private RecordManualTransaction $recordManualTransaction,
        private ReadLedger $readLedger,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render(
            'transactions/index',
            $this->readLedger->handle($request->user()),
        );
    }

    public function store(StoreManualTransactionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->recordManualTransaction->handle(
            owner: $request->user(),
            occurredOn: CarbonImmutable::parse($validated['occurred_on'], config('app.timezone')),
            amountMinor: (int) $validated['amount_minor'],
            currency: Currency::from($validated['currency']),
            kind: TransactionKind::from($validated['kind']),
            merchantDescription: $validated['merchant_description'],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaction recorded.'),
        ]);

        return to_route('transactions.index');
    }
}
