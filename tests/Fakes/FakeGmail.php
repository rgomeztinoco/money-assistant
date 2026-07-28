<?php

namespace Tests\Fakes;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailAccess;
use App\Integrations\Gmail\GmailAuthorization;
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

    /** @var list<array{state: string, login_hint: string|null}> */
    public array $authorizationUrlCalls = [];

    /** @var list<string> */
    public array $authorizationCodes = [];

    /** @var list<string> */
    public array $refreshTokens = [];

    /** @var list<string> */
    public array $profileAccessTokens = [];

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
        $this->profileAccessTokens[] = $accessToken;

        return $this->profile ?? throw new RuntimeException('No fake Gmail profile was configured.');
    }
}
