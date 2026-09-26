<?php

namespace App\Actions\NotificationIngestion;

use App\Integrations\Gmail\GmailRequestFailed;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;

final class ProcessDiscoveredGmailMessage
{
    public function __construct(
        private ReadGmailMessage $readGmailMessage,
        private ProcessSpendingNotification $processSpendingNotification,
    ) {}

    public function handle(
        int $discoveryId,
        bool $retryUnsupported = false,
    ): SpendingNotificationReference {
        $discovery = GmailMessageDiscovery::query()
            ->with(['gmailConnection.owner'])
            ->findOrFail($discoveryId);
        $connection = $discovery->gmailConnection;
        $owner = $connection->owner;

        $existingReference = SpendingNotificationReference::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('gmail_account_identity', $connection->gmail_account_identity)
            ->where('message_id', $discovery->message_id)
            ->first();

        if ($existingReference !== null
            && (! $retryUnsupported || ! $existingReference->isRetryable())) {
            if ($existingReference->gmail_message_discovery_id !== $discovery->id) {
                $existingReference->forceFill(['gmail_message_discovery_id' => $discovery->id])->save();
            }

            return $this->completeProcessing($discovery, $existingReference);
        }

        try {
            $message = $this->readGmailMessage->handle($owner, $discovery);
        } catch (GmailRequestFailed $exception) {
            if ($exception->httpStatus() !== 404) {
                throw $exception;
            }

            return $this->completeProcessing(
                $discovery,
                $this->processSpendingNotification->recordMissingMessage(
                    owner: $owner,
                    discovery: $discovery,
                ),
            );
        }

        return $this->completeProcessing(
            $discovery,
            $this->processSpendingNotification->handle(
                owner: $owner,
                discovery: $discovery,
                message: $message,
                retryUnsupported: $retryUnsupported,
            ),
        );
    }

    private function completeProcessing(
        GmailMessageDiscovery $discovery,
        SpendingNotificationReference $reference,
    ): SpendingNotificationReference {
        $discovery->forceFill([
            'processed_at' => $discovery->processed_at ?? now(),
            'processing_failed_at' => null,
            'last_error_code' => null,
            'failed_job_uuid' => null,
        ])->save();

        return $reference;
    }
}
