<?php

namespace App\Actions\Ledger;

use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarkSuspectedDuplicate
{
    public function handle(
        User $owner,
        Transaction $firstTransaction,
        Transaction $secondTransaction,
    ): SuspectedDuplicate {
        if ($firstTransaction->is($secondTransaction)) {
            throw new InvalidArgumentException('A Transaction cannot be a Suspected Duplicate of itself.');
        }

        $transactionIds = [
            (int) $firstTransaction->getKey(),
            (int) $secondTransaction->getKey(),
        ];
        sort($transactionIds);

        return DB::transaction(function () use ($owner, $transactionIds): SuspectedDuplicate {
            $transactions = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionIds)
                ->lockForUpdate()
                ->get();

            if ($transactions->count() !== 2) {
                throw new InvalidArgumentException('Suspected Duplicates must belong to the same owner.');
            }

            if ($transactions->contains(
                fn (Transaction $transaction): bool => $transaction->voided_at !== null,
            )) {
                throw new InvalidArgumentException('Voided Transactions cannot become Suspected Duplicates.');
            }

            return SuspectedDuplicate::query()->firstOrCreate([
                'user_id' => $owner->getKey(),
                'first_transaction_id' => $transactionIds[0],
                'second_transaction_id' => $transactionIds[1],
            ]);
        }, 3);
    }
}
