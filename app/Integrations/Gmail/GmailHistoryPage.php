<?php

namespace App\Integrations\Gmail;

final readonly class GmailHistoryPage
{
    /** @param list<string> $messageIds */
    public function __construct(
        public array $messageIds,
        public string $historyId,
        public ?string $nextPageToken,
    ) {}
}
