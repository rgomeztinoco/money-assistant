<?php

namespace App\Actions\NotificationIngestion;

use App\Actions\Ledger\RecordManualTransaction;
use App\Currency;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\SpendingNotificationProcessingOutcome;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoverSpendingNotification
{
    public function __construct(private RecordManualTransaction $recordManualTransaction) {}

    public function handle(
        User $owner,
        SpendingNotificationReference $reference,
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $description,
    ): Transaction {
        return DB::transaction(function () use ($owner, $reference, $occurredOn, $amountMinor, $currency, $kind, $description): Transaction {
            $reference = SpendingNotificationReference::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->findOrFail($reference->id);

            if (! $reference->isRecoverable()) {
                throw new InvalidArgumentException(
                    'Only an authenticated unresolved message may be recorded manually.',
                );
            }

            $transaction = $this->recordManualTransaction->handle(
                owner: $owner,
                occurredOn: $occurredOn,
                amountMinor: $amountMinor,
                currency: $currency,
                kind: $kind,
                direction: $kind === TransactionKind::Refund
                    ? MovementDirection::Credit
                    : MovementDirection::Debit,
                description: $description,
            );
            $reference->forceFill([
                'transaction_id' => $transaction->id,
                'processing_outcome' => SpendingNotificationProcessingOutcome::Created->value,
                'last_attempted_at' => now(),
            ])->save();

            return $transaction;
        }, 3);
    }
}
