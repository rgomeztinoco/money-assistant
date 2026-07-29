<?php

namespace App\Integrations\Gmail;

final readonly class GmailProfile
{
    public function __construct(
        public string $accountIdentity,
        public string $historyId,
    ) {}
}
