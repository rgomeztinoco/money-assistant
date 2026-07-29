<?php

namespace Tests\Fakes;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailAccess;
use App\Integrations\Gmail\GmailAuthorization;
use App\Integrations\Gmail\GmailHistoryPage;
use App\Integrations\Gmail\GmailMessageIdentity;
use App\Integrations\Gmail\GmailMessagePage;
use App\Integrations\Gmail\GmailProfile;
use RuntimeException;
use Throwable;

final class FakeGmail implements Gmail
{
    public string $authorizationUrl = 'https://accounts.google.test/oauth';

    public ?GmailAuthorization $authorization = null;

    public ?GmailAccess $access = null;

    public ?GmailProfile $profile = null;

    public ?Throwable $authorizationFailure = null;

    public ?Throwable $refreshFailure = null;

    public ?Throwable $historyFailure = null;

    /** @var list<string> */
    public array $operations = [];

    /** @var list<array{state: string, login_hint: string|null}> */
    public array $authorizationUrlCalls = [];

    /** @var list<string> */
    public array $authorizationCodes = [];

    /** @var list<string> */
    public array $refreshTokens = [];

    /** @var list<string> */
    public array $profileAccessTokens = [];

    /** @var list<GmailHistoryPage> */
    public array $historyPages = [];

    /** @var list<array{access_token: string, start_history_id: string, page_token: string|null}> */
    public array $historyCalls = [];

    /** @var list<GmailMessagePage> */
    public array $messagePages = [];

    /** @var array<string, GmailMessageIdentity> */
    public array $messageIdentities = [];

    /** @var list<array{access_token: string, after_epoch_seconds: int, page_token: string|null}> */
    public array $messagesAfterCalls = [];

    /** @var list<array{access_token: string, message_id: string}> */
    public array $messageIdentityCalls = [];

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $this->authorizationUrlCalls[] = [
            'state' => $state,
            'login_hint' => $loginHint,
        ];

        return $this->authorizationUrl;
    }

    public function authorize(string $code): GmailAuthorization
    {
        $this->authorizationCodes[] = $code;

        if ($this->authorizationFailure !== null) {
            throw $this->authorizationFailure;
        }

        return $this->authorization ?? throw new RuntimeException('No fake Gmail authorization was configured.');
    }

    public function refresh(string $refreshToken): GmailAccess
    {
        $this->refreshTokens[] = $refreshToken;

        if ($this->refreshFailure !== null) {
            throw $this->refreshFailure;
        }

        return $this->access ?? throw new RuntimeException('No fake Gmail access was configured.');
    }

    public function profile(string $accessToken): GmailProfile
    {
        $this->operations[] = 'profile';
        $this->profileAccessTokens[] = $accessToken;

        return $this->profile ?? throw new RuntimeException('No fake Gmail profile was configured.');
    }

    public function history(
        string $accessToken,
        string $startHistoryId,
        ?string $pageToken = null,
    ): GmailHistoryPage {
        $this->operations[] = 'history';
        $this->historyCalls[] = [
            'access_token' => $accessToken,
            'start_history_id' => $startHistoryId,
            'page_token' => $pageToken,
        ];

        if ($this->historyFailure !== null) {
            $failure = $this->historyFailure;
            $this->historyFailure = null;

            throw $failure;
        }

        return array_shift($this->historyPages)
            ?? throw new RuntimeException('No fake Gmail history page was configured.');
    }

    public function messagesAfter(
        string $accessToken,
        int $afterEpochSeconds,
        ?string $pageToken = null,
    ): GmailMessagePage {
        $this->operations[] = 'messages_after';
        $this->messagesAfterCalls[] = [
            'access_token' => $accessToken,
            'after_epoch_seconds' => $afterEpochSeconds,
            'page_token' => $pageToken,
        ];

        return array_shift($this->messagePages)
            ?? throw new RuntimeException('No fake Gmail message page was configured.');
    }

    public function messageIdentity(
        string $accessToken,
        string $messageId,
    ): GmailMessageIdentity {
        $this->operations[] = 'message_identity';
        $this->messageIdentityCalls[] = [
            'access_token' => $accessToken,
            'message_id' => $messageId,
        ];

        return $this->messageIdentities[$messageId]
            ?? throw new RuntimeException("No fake Gmail identity was configured for [{$messageId}].");
    }
}
