<?php

namespace App\Actions\NotificationIngestion;

use App\Actions\Ledger\RecordManualTransaction;
use App\Currency;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoverSpendingNotification
{
    public function __construct(
        private RecordManualTransaction $recordManualTransaction,
        private SynchronizeParserProfileAlerts $synchronizeParserProfileAlerts,
    ) {}

    public function handle(
        User $owner,
        SpendingNotificationReference $reference,
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $merchantDescription,
    ): Transaction {
        $transaction = DB::transaction(function () use ($owner, $reference, $occurredOn, $amountMinor, $currency, $kind, $merchantDescription): Transaction {
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
                merchantDescription: $merchantDescription,
            );
            $reference->forceFill([
                'transaction_id' => $transaction->id,
                'processing_outcome' => SpendingNotificationProcessingOutcome::Created->value,
                'last_attempted_at' => now(),
            ])->save();

            return $transaction;
        }, 3);
        $profileId = $reference->fresh()?->profileVersion()->value('parser_profile_id');

        if (is_int($profileId)) {
            $this->synchronizeParserProfileAlerts->handle($owner, $profileId);
        }

        return $transaction;
    }
}
