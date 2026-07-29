<?php

namespace App\Integrations\Gmail;

use Carbon\CarbonImmutable;

final readonly class GmailMessageIdentity
{
    public function __construct(
        public string $messageId,
        public CarbonImmutable $receivedAt,
    ) {}
}
