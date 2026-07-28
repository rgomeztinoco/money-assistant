<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Integrations\Gmail\GoogleGmail;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the Google adapter requests offline access to only the read-only Gmail scope', function () {
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => HandlerStack::create(new MockHandler)]),
    );

    $authorizationUrl = $gmail->authorizationUrl(
        state: 'opaque-state',
        loginHint: 'owner@example.test',
    );
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    expect(parse_url($authorizationUrl, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($authorizationUrl, PHP_URL_HOST))->toBe('accounts.google.com')
        ->and(parse_url($authorizationUrl, PHP_URL_PATH))->toBe('/o/oauth2/v2/auth')
        ->and($query)->toMatchArray([
            'access_type' => 'offline',
            'client_id' => 'google-client-id',
            'include_granted_scopes' => 'false',
            'login_hint' => 'owner@example.test',
            'prompt' => 'consent',
            'redirect_uri' => 'https://money.example.test/settings/connections/gmail/callback',
            'response_type' => 'code',
            'scope' => Gmail::READ_ONLY_SCOPE,
            'state' => 'opaque-state',
        ]);
});

test('the Google adapter exchanges a web-server code and reads the Gmail account profile', function () {
    CarbonImmutable::setTestNow('2026-07-28 16:00:00 UTC');
    $requests = [];
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'access-token',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-token',
            'scope' => Gmail::READ_ONLY_SCOPE,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)),
        function (RequestInterface $request): Response {
            expect($request->getHeaderLine('Authorization'))->toBe('Bearer access-token');

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'emailAddress' => 'receipts@example.test',
                'historyId' => '123456',
                'messagesTotal' => 12,
                'threadsTotal' => 10,
            ], JSON_THROW_ON_ERROR));
        },
    ]);
    $handlerStack = HandlerStack::create($mockHandler);
    $handlerStack->push(Middleware::history($requests));
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => $handlerStack]),
    );

    $authorization = $gmail->authorize('authorization-code');

    expect($authorization->accessToken)->toBe('access-token')
        ->and($authorization->refreshToken)->toBe('refresh-token')
        ->and($authorization->accessTokenExpiresAt->toIso8601String())->toBe('2026-07-28T17:00:00+00:00')
        ->and($authorization->grantedScopes)->toBe([Gmail::READ_ONLY_SCOPE])
        ->and($authorization->accountIdentity)->toBe('receipts@example.test')
        ->and($requests)->toHaveCount(2);

    parse_str((string) $requests[0]['request']->getBody(), $tokenRequest);

    expect($requests[0]['request'])->toBeInstanceOf(RequestInterface::class)
        ->and($requests[0]['request']->getMethod())->toBe('POST')
        ->and((string) $requests[0]['request']->getUri())->toBe('https://oauth2.googleapis.com/token')
        ->and($tokenRequest)->toMatchArray([
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
            'code' => 'authorization-code',
            'grant_type' => 'authorization_code',
            'redirect_uri' => 'https://money.example.test/settings/connections/gmail/callback',
        ])
        ->and($requests[1]['request']->getMethod())->toBe('GET')
        ->and((string) $requests[1]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/me/profile');
});

test('the Google adapter rejects broader grants without exposing authorization secrets', function () {
    $requests = [];
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'sensitive-access-token',
            'expires_in' => 3600,
            'refresh_token' => 'sensitive-refresh-token',
            'scope' => Gmail::READ_ONLY_SCOPE.' https://mail.google.com/',
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $handlerStack = HandlerStack::create($mockHandler);
    $handlerStack->push(Middleware::history($requests));
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => $handlerStack]),
    );

    try {
        $gmail->authorize('sensitive-authorization-code');
        $this->fail('A broader Gmail grant was accepted.');
    } catch (GmailRequestFailed $exception) {
        expect($exception->getMessage())->not->toContain('sensitive')
            ->and($exception->getPrevious())->toBeNull();
    }

    expect($requests)->toHaveCount(1);
});

test('the Google adapter refreshes offline access without widening its scope', function () {
    CarbonImmutable::setTestNow('2026-07-28 18:00:00 UTC');
    $requests = [];
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'refreshed-access-token',
            'expires_in' => 3600,
            'scope' => Gmail::READ_ONLY_SCOPE,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $handlerStack = HandlerStack::create($mockHandler);
    $handlerStack->push(Middleware::history($requests));
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => $handlerStack]),
    );

    $access = $gmail->refresh('offline-refresh-token');

    parse_str((string) $requests[0]['request']->getBody(), $tokenRequest);

    expect($access->accessToken)->toBe('refreshed-access-token')
        ->and($access->accessTokenExpiresAt->toIso8601String())->toBe('2026-07-28T19:00:00+00:00')
        ->and($requests)->toHaveCount(1)
        ->and((string) $requests[0]['request']->getUri())->toBe('https://oauth2.googleapis.com/token')
        ->and($tokenRequest)->toMatchArray([
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
            'grant_type' => 'refresh_token',
            'refresh_token' => 'offline-refresh-token',
        ]);
});

test('the Google adapter identifies a terminal refresh rejection without exposing tokens', function () {
    $mockHandler = new MockHandler([
        new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => HandlerStack::create($mockHandler)]),
    );

    try {
        $gmail->refresh('sensitive-refresh-token');
        $this->fail('A rejected refresh token was accepted.');
    } catch (GmailReauthorizationRequired $exception) {
        expect($exception->getMessage())->not->toContain('sensitive')
            ->and($exception->getPrevious())->toBeNull();
    }
});
