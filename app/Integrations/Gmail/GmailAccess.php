<?php

namespace App\Integrations\Gmail;

use Carbon\CarbonImmutable;

final readonly class GmailAccess
{
    public function __construct(
        public string $accessToken,
        public CarbonImmutable $accessTokenExpiresAt,
    ) {}
}
