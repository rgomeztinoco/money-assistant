<?php

namespace App\Integrations\Gmail;

use Carbon\CarbonImmutable;

final readonly class GmailMessageSummary
{
    public function __construct(
        public string $messageId,
        public CarbonImmutable $receivedAt,
        public string $fromAddress,
        public string $subject,
    ) {}
}
