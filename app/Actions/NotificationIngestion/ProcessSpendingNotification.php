<?php

namespace App\Actions\NotificationIngestion;

use App\Actions\Ledger\RecordManualTransaction;
use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;
use App\Models\User;
use App\NotificationIngestion\SupportedSpendingNotificationRegistry;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProcessSpendingNotification
{
    public function __construct(
        private SupportedSpendingNotificationRegistry $supportedFormats,
        private RecordManualTransaction $recordTransaction,
    ) {}

    public function handle(
        User $owner,
        GmailMessageDiscovery $discovery,
        GmailMessage $message,
        bool $retryUnsupported = false,
    ): SpendingNotificationReference {
        return DB::transaction(function () use (
            $owner,
            $discovery,
            $message,
            $retryUnsupported,
        ): SpendingNotificationReference {
            $discovery = GmailMessageDiscovery::query()
                ->with('gmailConnection')
                ->lockForUpdate()
                ->findOrFail($discovery->id);
            $accountIdentity = $discovery->gmailConnection->gmail_account_identity;
            $existingReference = SpendingNotificationReference::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('gmail_account_identity', $accountIdentity)
                ->where('message_id', $message->messageId)
                ->first();

            if ($existingReference !== null && ! $retryUnsupported) {
                return $existingReference;
            }

            if ($existingReference !== null && ! $existingReference->isRetryable()) {
                throw new InvalidArgumentException(
                    'Only an unresolved unsupported message may be retried.',
                );
            }

            try {
                $match = $this->supportedFormats->match($message);
            } catch (InvalidArgumentException) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Failed,
                );
            }

            if ($match === null) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Unsupported,
                );
            }

            $extraction = $match->extraction;
            $transaction = $this->recordTransaction->handle(
                owner: $owner,
                occurredOn: $extraction->occurredOn,
                amountMinor: $extraction->amountMinor,
                currency: $extraction->currency,
                kind: $extraction->kind,
                direction: $extraction->direction,
                incomeSource: $extraction->incomeSource,
                transferPurpose: $extraction->transferPurpose,
                description: $extraction->description,
                provisionalFields: $extraction->provisionalFields,
                instrumentLabel: $extraction->instrumentLabel,
                instrumentLastFour: $extraction->instrumentLastFour,
            );

            return $this->recordOutcome(
                owner: $owner,
                discovery: $discovery,
                accountIdentity: $accountIdentity,
                messageId: $message->messageId,
                outcome: $extraction->provisionalFields === []
                    ? SpendingNotificationProcessingOutcome::Created
                    : SpendingNotificationProcessingOutcome::CreatedWithReview,
                formatIdentifier: $match->formatIdentifier,
                transactionId: $transaction->id,
            );
        }, 3);
    }

    private function recordOutcome(
        User $owner,
        GmailMessageDiscovery $discovery,
        string $accountIdentity,
        string $messageId,
        SpendingNotificationProcessingOutcome $outcome,
        ?string $formatIdentifier = null,
        ?int $transactionId = null,
    ): SpendingNotificationReference {
        $reference = SpendingNotificationReference::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('gmail_account_identity', $accountIdentity)
            ->where('message_id', $messageId)
            ->first();
        $attemptCount = $reference === null
            ? 1
            : $reference->attempt_count + 1;
        $attributes = [
            'user_id' => $owner->getKey(),
            'transaction_id' => $transactionId,
            'gmail_account_identity' => $accountIdentity,
            'message_id' => $messageId,
            'processing_outcome' => $outcome->value,
            'format_identifier' => $formatIdentifier,
            'gmail_message_discovery_id' => $discovery->id,
            'attempt_count' => $attemptCount,
            'last_attempted_at' => now(),
        ];

        if ($reference === null) {
            $reference = SpendingNotificationReference::create($attributes);
        } else {
            $reference->forceFill($attributes)->save();
        }
        $discovery->forceFill(['processed_at' => now()])->save();

        return $reference;
    }
}
