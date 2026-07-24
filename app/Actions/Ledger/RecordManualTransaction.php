<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordManualTransaction
{
    public function handle(
        User $owner,
        CarbonImmutable $occurredOn,
        mixed $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $merchantDescription,
    ): Transaction {
        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new InvalidArgumentException('A Transaction amount must be positive.');
        }

        $merchantDescription = Str::squish($merchantDescription);

        if ($merchantDescription === '') {
            throw new InvalidArgumentException('A merchant or short description is required.');
        }

        return DB::transaction(fn (): Transaction => Transaction::create([
            'user_id' => $owner->getKey(),
            'occurred_on' => $occurredOn,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'kind' => $kind,
            'merchant_description' => $merchantDescription,
            'confirmed_at' => now(),
        ]));
    }
}
