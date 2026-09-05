<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ReadGmailMessage
{
    public function __construct(
        private Gmail $gmail,
        private RefreshGmailConnection $refreshGmailConnection,
    ) {}

    public function handle(
        User $owner,
        GmailMessageDiscovery $discovery,
    ): GmailMessage {
        $discovery->loadMissing('gmailConnection');

        if ($discovery->gmailConnection->user_id !== $owner->getKey()) {
            throw (new ModelNotFoundException)->setModel(GmailMessageDiscovery::class);
        }

        $connection = $discovery->gmailConnection;

        if ($connection->access_token_expires_at->lessThanOrEqualTo(now()->addMinute())) {
            $connection = $this->refreshGmailConnection->handle($connection);
        }

        $message = $this->gmail->message(
            $connection->access_token,
            $discovery->message_id,
        );

        if ($message->messageId !== $discovery->message_id) {
            throw new ModelNotFoundException;
        }

        return $message;
    }
}
