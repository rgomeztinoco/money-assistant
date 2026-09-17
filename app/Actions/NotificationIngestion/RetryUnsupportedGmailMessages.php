<?php

namespace App\Actions\NotificationIngestion;

use App\Jobs\ProcessGmailMessage;
use App\Models\GmailConnection;
use App\Models\SpendingNotificationReference;
use App\Models\User;

class RetryUnsupportedGmailMessages
{
    public function handle(User $owner): int
    {
        $connection = GmailConnection::query()
            ->whereBelongsTo($owner, 'owner')
            ->first();

        if ($connection === null || $connection->ingestionIsPaused()) {
            return 0;
        }

        $discoveryIds = SpendingNotificationReference::query()
            ->whereRetryableUnsupportedFor($connection)
            ->pluck('gmail_message_discovery_id')
            ->unique()
            ->values();

        foreach ($discoveryIds as $discoveryId) {
            ProcessGmailMessage::dispatch((int) $discoveryId, retryUnsupported: true);
        }

        return $discoveryIds->count();
    }
}
