<?php

namespace App\Contracts;

use App\Integrations\Gmail\GmailAccess;
use App\Integrations\Gmail\GmailAuthorization;
use App\Integrations\Gmail\GmailProfile;

interface Gmail
{
    public const READ_ONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public function authorizationUrl(string $state, ?string $loginHint = null): string;

    public function authorize(string $code): GmailAuthorization;

    public function refresh(string $refreshToken): GmailAccess;

    public function profile(string $accessToken): GmailProfile;
}
