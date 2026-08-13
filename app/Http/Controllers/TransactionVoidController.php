<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\ChangeTransactionVoidState;
use App\Http\Requests\ChangeTransactionVoidStateRequest;
use App\Models\Transaction;
use App\TransactionVoidOperation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

class TransactionVoidController extends Controller
{
    public function __construct(
        private ChangeTransactionVoidState $changeTransactionVoidState,
    ) {}

    public function store(
        ChangeTransactionVoidStateRequest $request,
        Transaction $transaction,
    ): RedirectResponse {
        return $this->changeState($request, $transaction, TransactionVoidOperation::Void);
    }

    public function destroy(
        ChangeTransactionVoidStateRequest $request,
        Transaction $transaction,
    ): RedirectResponse {
        return $this->changeState($request, $transaction, TransactionVoidOperation::Restore);
    }

    private function changeState(
        ChangeTransactionVoidStateRequest $request,
        Transaction $transaction,
        TransactionVoidOperation $operation,
    ): RedirectResponse {
        try {
            $this->changeTransactionVoidState->handle(
                transaction: $transaction,
                operation: $operation,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->stateErrorResponse('void_state', $exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $operation === TransactionVoidOperation::Void
                ? __('Transaction voided.')
                : __('Transaction restored.'),
        ]);

        return $this->redirectToWorkspace('transactions.index');
    }

    private function stateErrorResponse(string $field, Throwable $exception): RedirectResponse
    {
        Inertia::flash('transaction_state_error', $exception->getMessage());

        return back()->withErrors([
            $field => $exception->getMessage(),
        ]);
    }
}
