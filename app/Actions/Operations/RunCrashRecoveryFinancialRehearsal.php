<?php

namespace App\Actions\Operations;

use App\Actions\Ledger\ChangeTransactionVoidState;
use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use App\TransactionVoidOperation;
use Illuminate\Support\Facades\DB;

class RunCrashRecoveryFinancialRehearsal
{
    public function __construct(private ChangeTransactionVoidState $changeTransactionVoidState) {}

    public function handle(string $rehearsalId): Transaction
    {
        return DB::transaction(function () use ($rehearsalId): Transaction {
            $existingTransaction = Transaction::query()
                ->where('deployment_rehearsal_id', $rehearsalId)
                ->lockForUpdate()
                ->first();

            if ($existingTransaction !== null) {
                return $existingTransaction;
            }

            $owner = User::query()->sole();
            $transaction = new Transaction;
            $transaction->forceFill([
                'user_id' => $owner->getKey(),
                'occurred_on' => today(),
                'amount_minor' => 1,
                'currency' => Currency::Pen,
                'kind' => TransactionKind::Purchase,
                'merchant_description' => 'Production trust crash rehearsal',
                'confirmed_at' => now(),
                'provisional_fields' => [],
                'deployment_rehearsal_id' => $rehearsalId,
            ])->save();
            $transaction->refresh();

            $this->changeTransactionVoidState->handle(
                owner: $owner,
                transaction: $transaction,
                operation: TransactionVoidOperation::Void,
            );

            return $transaction->refresh();
        });
    }
}
