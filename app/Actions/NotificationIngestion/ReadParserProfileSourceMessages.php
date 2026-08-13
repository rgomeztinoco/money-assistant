<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Models\GmailMessageDiscovery;

final class ReadParserProfileSourceMessages
{
    public function __construct(
        private Gmail $gmail,
        private RefreshGmailConnection $refreshGmailConnection,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     message_id: string,
     *     received_at: string,
     *     from_address: string,
     *     subject: string
     * }>
     */
    public function handle(): array
    {
        $discoveries = GmailMessageDiscovery::query()
            ->whereNull('processed_at')
            ->with('gmailConnection')
            ->latest()
            ->limit(20)
            ->get();
        $connections = [];
        $sourceMessages = [];

        foreach ($discoveries as $discovery) {
            $connection = $connections[$discovery->gmail_connection_id]
                ?? $discovery->gmailConnection;

            if ($connection->access_token_expires_at->lessThanOrEqualTo(now()->addMinute())) {
                $connection = $this->refreshGmailConnection->handle($connection);
            }

            $connections[$connection->id] = $connection;

            try {
                $summary = $this->gmail->messageSummary(
                    $connection->access_token,
                    $discovery->message_id,
                );
            } catch (GmailRequestFailed) {
                continue;
            }

            $sourceMessages[] = [
                'id' => $discovery->id,
                'message_id' => $summary->messageId,
                'received_at' => $summary->receivedAt->toIso8601String(),
                'from_address' => $summary->fromAddress,
                'subject' => $summary->subject,
            ];
        }

        return $sourceMessages;
    }
}
