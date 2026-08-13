<?php

namespace App\Actions\NotificationIngestion;

use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;

final class ProcessDiscoveredGmailMessage
{
    public function __construct(
        private ReadParserProfileSourceMessage $readSourceMessage,
        private ProcessSpendingNotification $processSpendingNotification,
    ) {}

    public function handle(int $discoveryId): SpendingNotificationReference
    {
        $discovery = GmailMessageDiscovery::query()
            ->with(['gmailConnection.owner'])
            ->findOrFail($discoveryId);
        $connection = $discovery->gmailConnection;
        $owner = $connection->owner;

        if ($discovery->processed_at !== null) {
            $existingReference = SpendingNotificationReference::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('gmail_account_identity', $connection->gmail_account_identity)
                ->where('message_id', $discovery->message_id)
                ->first();

            if ($existingReference !== null) {
                return $existingReference;
            }
        }

        $reference = $this->processSpendingNotification->handle(
            owner: $owner,
            discovery: $discovery,
            message: $this->readSourceMessage->sourceMessage($owner, $discovery),
        );

        $discovery->forceFill([
            'processing_failed_at' => null,
            'last_error_code' => null,
            'failed_job_uuid' => null,
        ])->save();

        return $reference;
    }
}
