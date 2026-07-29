<?php

namespace App\Integrations\Gmail;

final readonly class GmailMessagePage
{
    /** @param list<string> $messageIds */
    public function __construct(
        public array $messageIds,
        public ?string $nextPageToken,
    ) {}
}
