<?php

namespace App\Integrations\Gmail;

use App\Contracts\Gmail;
use Carbon\CarbonImmutable;
use LogicException;

final class PreviewGmail implements Gmail
{
    public const string ACCOUNT_IDENTITY = 'preview@money-assistant.test';

    public const string ACCESS_TOKEN = 'local-preview-access-token';

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        throw new LogicException('The Gmail preview cannot authorize an account.');
    }

    public function authorize(string $code): GmailAuthorization
    {
        throw new LogicException('The Gmail preview cannot authorize an account.');
    }

    public function refresh(string $refreshToken): GmailAccess
    {
        return new GmailAccess(self::ACCESS_TOKEN, CarbonImmutable::now()->addHour());
    }

    public function profile(string $accessToken): GmailProfile
    {
        return new GmailProfile(self::ACCOUNT_IDENTITY, 'preview-history');
    }

    public function history(string $accessToken, string $startHistoryId, ?string $pageToken = null): GmailHistoryPage
    {
        return new GmailHistoryPage([], 'preview-history', null);
    }

    public function messagesAfter(string $accessToken, int $afterEpochSeconds, ?string $pageToken = null): GmailMessagePage
    {
        return new GmailMessagePage([], null);
    }

    public function messageIdentity(string $accessToken, string $messageId): GmailMessageIdentity
    {
        $summary = $this->messageSummary($accessToken, $messageId);

        return new GmailMessageIdentity($summary->messageId, $summary->receivedAt);
    }

    public function message(string $accessToken, string $messageId): GmailMessage
    {
        $summary = $this->messageSummary($accessToken, $messageId);

        return new GmailMessage(
            messageId: $summary->messageId,
            receivedAt: $summary->receivedAt,
            fromAddress: $summary->fromAddress,
            subject: $summary->subject,
            authentication: [
                'spf' => ['result' => null, 'domain' => null],
                'dkim' => ['result' => null, 'domain' => null],
                'dmarc' => ['result' => null, 'domain' => null],
            ],
            textBody: 'Sample message for the local Gmail review preview.',
            htmlBody: null,
        );
    }

    public function messageSummary(string $accessToken, string $messageId): GmailMessageSummary
    {
        if ($accessToken !== self::ACCESS_TOKEN || ! preg_match('/^preview-(\d{3})$/', $messageId, $matches)) {
            throw GmailRequestFailed::messageSummary()->withHttpStatus(404);
        }

        $number = (int) $matches[1];

        if ($number === 5) {
            throw GmailRequestFailed::messageSummary()->withHttpStatus(404);
        }

        if ($number === 11) {
            throw GmailRequestFailed::messageSummary()->withHttpStatus(503);
        }

        $subjects = [
            'Sample card purchase notice',
            'Sample transfer confirmation',
            'Sample payment reminder',
            'Sample store receipt',
            'Sample account update',
        ];
        $senders = [
            'alerts@example.test',
            'receipts@example.test',
            'updates@example.test',
        ];

        return new GmailMessageSummary(
            messageId: $messageId,
            threadId: '',
            receivedAt: CarbonImmutable::now()->subDays($number)->startOfDay()->addHours(9 + ($number % 8)),
            fromAddress: $senders[$number % count($senders)],
            subject: $subjects[$number % count($subjects)].' #'.$number,
        );
    }
}
