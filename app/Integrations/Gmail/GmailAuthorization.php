<?php

namespace App\Integrations\Gmail;

use Carbon\CarbonImmutable;

final readonly class GmailAuthorization
{
    /** @param list<string> $grantedScopes */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $accessTokenExpiresAt,
        public array $grantedScopes,
        public string $accountIdentity,
        public string $historyId,
    ) {}
}
