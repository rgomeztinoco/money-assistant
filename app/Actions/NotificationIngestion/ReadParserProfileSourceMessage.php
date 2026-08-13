<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class ReadParserProfileSourceMessage
{
    public function __construct(
        private Gmail $gmail,
        private RefreshGmailConnection $refreshGmailConnection,
    ) {}

    /**
     * @return array{
     *     message_id: string,
     *     received_at: string,
     *     from_address: string,
     *     subject: string,
     *     authentication: array<string, array{result: string|null, domain: string|null, aligned: bool}>,
     *     mime_parts: array{text_plain: string|null, text_html: string|null}
     * }
     */
    public function handle(GmailMessageDiscovery $discovery): array
    {
        return $this->sourceData($this->sourceMessage($discovery));
    }

    public function sourceMessage(GmailMessageDiscovery $discovery): GmailMessage
    {
        $discovery->loadMissing('gmailConnection');

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

    /**
     * @return array{
     *     message_id: string,
     *     received_at: string,
     *     from_address: string,
     *     subject: string,
     *     authentication: array<string, array{result: string|null, domain: string|null, aligned: bool}>,
     *     mime_parts: array{text_plain: string|null, text_html: string|null}
     * }
     */
    private function sourceData(GmailMessage $message): array
    {
        $fromDomain = Str::lower(Str::afterLast($message->fromAddress, '@'));
        $authentication = [];

        foreach ($message->authentication as $mechanism => $result) {
            $authentication[$mechanism] = [
                ...$result,
                'aligned' => $result['result'] === 'pass'
                    && $result['domain'] !== null
                    && hash_equals($fromDomain, Str::lower($result['domain'])),
            ];
        }

        return [
            'message_id' => $message->messageId,
            'received_at' => $message->receivedAt->toIso8601String(),
            'from_address' => $message->fromAddress,
            'subject' => $message->subject,
            'authentication' => $authentication,
            'mime_parts' => [
                'text_plain' => $message->textBody,
                'text_html' => $message->htmlBody,
            ],
        ];
    }
}
