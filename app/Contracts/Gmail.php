<?php

namespace App\Contracts;

use App\Integrations\Gmail\GmailAccess;
use App\Integrations\Gmail\GmailAuthorization;
use App\Integrations\Gmail\GmailHistoryPage;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageIdentity;
use App\Integrations\Gmail\GmailMessagePage;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Integrations\Gmail\GmailProfile;

interface Gmail
{
    public const READ_ONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public function authorizationUrl(string $state, ?string $loginHint = null): string;

    public function authorize(string $code): GmailAuthorization;

    public function refresh(string $refreshToken): GmailAccess;

    public function profile(string $accessToken): GmailProfile;

    public function history(
        string $accessToken,
        string $startHistoryId,
        ?string $pageToken = null,
    ): GmailHistoryPage;

    public function messagesAfter(
        string $accessToken,
        int $afterEpochSeconds,
        ?string $pageToken = null,
    ): GmailMessagePage;

    public function messageIdentity(
        string $accessToken,
        string $messageId,
    ): GmailMessageIdentity;

    public function message(
        string $accessToken,
        string $messageId,
    ): GmailMessage;

    public function messageSummary(
        string $accessToken,
        string $messageId,
    ): GmailMessageSummary;
}
