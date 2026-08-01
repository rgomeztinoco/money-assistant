<?php

use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Contracts\Gmail;
use App\IntegrationFailureKind;
use App\Integrations\Gmail\GmailHistoryExpired;
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
        ->and($authorization->historyId)->toBe('123456')
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

test('the Google adapter reads only added message identities from a history page', function () {
    $requests = [];
    $observedAuthorization = null;
    $mockHandler = new MockHandler([
        function (RequestInterface $request) use (&$observedAuthorization): Response {
            $observedAuthorization = $request->getHeaderLine('Authorization');

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'history' => [
                    [
                        'id' => '101',
                        'messages' => [
                            ['id' => 'generic-message', 'threadId' => 'thread-1'],
                        ],
                        'messagesAdded' => [
                            ['message' => ['id' => 'immutable-message-1', 'threadId' => 'thread-2']],
                            ['message' => ['id' => 'immutable-message-2', 'threadId' => 'thread-3']],
                        ],
                    ],
                ],
                'historyId' => '120',
                'nextPageToken' => 'next-history-page',
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

    $page = $gmail->history(
        accessToken: 'access-token',
        startHistoryId: '100',
        pageToken: 'requested-history-page',
    );

    expect($page->messageIds)->toBe(['immutable-message-1', 'immutable-message-2'])
        ->and($page->historyId)->toBe('120')
        ->and($page->nextPageToken)->toBe('next-history-page')
        ->and($requests)->toHaveCount(1);

    $request = $requests[0]['request'];
    parse_str((string) $request->getUri()->getQuery(), $query);

    expect($request->getMethod())->toBe('GET')
        ->and($observedAuthorization)->toBe('Bearer access-token')
        ->and($request->getUri()->getPath())->toBe('/gmail/v1/users/me/history')
        ->and($query)->toMatchArray([
            'historyTypes' => 'messageAdded',
            'maxResults' => '500',
            'pageToken' => 'requested-history-page',
            'startHistoryId' => '100',
        ]);
});

test('the Google adapter lists an overlapping mailbox window and reads only message identity metadata', function () {
    $requests = [];
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'messages' => [
                ['id' => 'immutable-message-1', 'threadId' => 'thread-1'],
                ['id' => 'immutable-message-2', 'threadId' => 'thread-2'],
            ],
            'nextPageToken' => 'next-message-page',
            'resultSizeEstimate' => 2,
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'immutable-message-1',
            'threadId' => 'thread-1',
            'historyId' => '130',
            'internalDate' => '1785258000000',
            'labelIds' => ['INBOX'],
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

    $page = $gmail->messagesAfter(
        accessToken: 'access-token',
        afterEpochSeconds: 1785250000,
        pageToken: 'requested-message-page',
    );
    $identity = $gmail->messageIdentity('access-token', 'immutable-message-1');

    expect($page->messageIds)->toBe(['immutable-message-1', 'immutable-message-2'])
        ->and($page->nextPageToken)->toBe('next-message-page')
        ->and($identity->messageId)->toBe('immutable-message-1')
        ->and($identity->receivedAt->toIso8601String())->toBe('2026-07-28T17:00:00+00:00')
        ->and($requests)->toHaveCount(2);

    parse_str((string) $requests[0]['request']->getUri()->getQuery(), $listQuery);
    parse_str((string) $requests[1]['request']->getUri()->getQuery(), $getQuery);

    expect($requests[0]['request']->getUri()->getPath())->toBe('/gmail/v1/users/me/messages')
        ->and($listQuery)->toMatchArray([
            'includeSpamTrash' => 'true',
            'maxResults' => '500',
            'pageToken' => 'requested-message-page',
            'q' => 'in:anywhere after:1785250000',
        ])
        ->and($requests[1]['request']->getUri()->getPath())->toBe('/gmail/v1/users/me/messages/immutable-message-1')
        ->and($getQuery)->toMatchArray([
            'fields' => 'id,internalDate',
            'format' => 'full',
        ]);
});

test('the Google adapter transiently reads authenticated headers and canonical MIME parts', function () {
    $requests = [];
    $plainBody = "Purchase approved\nAmount: S/ 125.40";
    $htmlBody = '<p>Purchase approved</p><p>Amount: S/ 125.40</p>';
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'immutable-message-1',
            'internalDate' => '1785258000000',
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [
                    ['name' => 'From', 'value' => 'Bank Alerts <alerts@bank.example>'],
                    ['name' => 'Subject', 'value' => 'Purchase approved'],
                    [
                        'name' => 'Authentication-Results',
                        'value' => 'upstream.example; dkim=pass header.d=lookalike.example; spf=pass smtp.mailfrom=alerts@lookalike.example; dmarc=pass header.from=lookalike.example',
                    ],
                    [
                        'name' => 'Authentication-Results',
                        'value' => 'mx.google.com; dkim=pass header.d=bank.example; spf=pass smtp.mailfrom=alerts@bank.example; dmarc=pass header.from=bank.example',
                    ],
                    [
                        'name' => 'Authentication-Results',
                        'value' => 'forged.example; dkim=fail header.d=lookalike.example; spf=fail smtp.mailfrom=alerts@lookalike.example; dmarc=fail header.from=lookalike.example',
                    ],
                ],
                'parts' => [
                    [
                        'mimeType' => 'text/plain',
                        'body' => ['data' => gmailBase64Url($plainBody)],
                    ],
                    [
                        'mimeType' => 'text/html',
                        'body' => ['data' => gmailBase64Url($htmlBody)],
                    ],
                ],
            ],
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

    $message = $gmail->message('access-token', 'immutable-message-1');

    expect($message->messageId)->toBe('immutable-message-1')
        ->and($message->receivedAt->toIso8601String())->toBe('2026-07-28T17:00:00+00:00')
        ->and($message->fromAddress)->toBe('alerts@bank.example')
        ->and($message->subject)->toBe('Purchase approved')
        ->and($message->authentication)->toBe([
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ])
        ->and($message->textBody)->toBe($plainBody)
        ->and($message->htmlBody)->toBe($htmlBody);

    parse_str((string) $requests[0]['request']->getUri()->getQuery(), $query);

    expect($query)->toMatchArray(['format' => 'full']);
});

test('the Google adapter ignores authentication results not asserted by Gmail', function () {
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'untrusted-authentication-results',
            'internalDate' => '1785258000000',
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => 'Bank Alerts <alerts@bank.example>'],
                    ['name' => 'Subject', 'value' => 'Purchase approved'],
                    [
                        'name' => 'Authentication-Results',
                        'value' => 'mx.google.com; dmarc=fail header.from=bank.example',
                    ],
                    [
                        'name' => 'Authentication-Results',
                        'value' => 'forged.example; dmarc=pass header.from=bank.example',
                    ],
                ],
                'body' => ['data' => gmailBase64Url('Purchase approved')],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => HandlerStack::create($mockHandler)]),
    );

    $message = $gmail->message(
        'access-token',
        'untrusted-authentication-results',
    );

    expect($message->authentication['dmarc'])->toBe([
        'result' => 'fail',
        'domain' => 'bank.example',
    ]);
});

test('the Google adapter lists source messages with metadata only', function () {
    $requests = [];
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'immutable-message-summary',
            'internalDate' => '1785258000000',
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [
                    ['name' => 'From', 'value' => 'Bank Alerts <alerts@bank.example>'],
                    ['name' => 'Subject', 'value' => 'Purchase approved'],
                ],
            ],
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

    $summary = $gmail->messageSummary(
        'access-token',
        'immutable-message-summary',
    );

    expect($summary->messageId)->toBe('immutable-message-summary')
        ->and($summary->fromAddress)->toBe('alerts@bank.example')
        ->and($summary->subject)->toBe('Purchase approved')
        ->and($summary->receivedAt->toIso8601String())->toBe('2026-07-28T17:00:00+00:00');

    parse_str((string) $requests[0]['request']->getUri()->getQuery(), $query);

    expect($query)->toMatchArray([
        'fields' => 'id,internalDate,payload(headers)',
        'format' => 'metadata',
    ])
        ->and((string) $requests[0]['request']->getUri())
        ->toContain('metadataHeaders=From')
        ->toContain('metadataHeaders=Subject');
});

test('the Google adapter identifies an expired history cursor without exposing mailbox data', function () {
    $mockHandler = new MockHandler([
        new Response(404, ['Content-Type' => 'application/json'], json_encode([
            'error' => [
                'code' => 404,
                'message' => 'Requested entity was not found for sensitive-history-100.',
                'status' => 'NOT_FOUND',
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => HandlerStack::create($mockHandler)]),
    );

    expect(fn () => $gmail->history(
        accessToken: 'sensitive-access-token',
        startHistoryId: 'sensitive-history-100',
    ))->toThrow(
        GmailHistoryExpired::class,
        'The Gmail history cursor has expired.',
    );
});

test('the Google adapter sanitizes message discovery failures', function () {
    $mockHandler = new MockHandler([
        new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['message' => 'sensitive subject and raw MIME from list'],
        ], JSON_THROW_ON_ERROR)),
        new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['message' => 'sensitive subject and raw MIME from message'],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => HandlerStack::create($mockHandler)]),
    );

    expect(fn () => $gmail->messagesAfter(
        accessToken: 'sensitive-access-token',
        afterEpochSeconds: 1785250000,
    ))->toThrow(
        GmailRequestFailed::class,
        'Gmail messages could not be discovered.',
    );

    expect(fn () => $gmail->messageIdentity(
        accessToken: 'sensitive-access-token',
        messageId: 'sensitive-message-id',
    ))->toThrow(
        GmailRequestFailed::class,
        'Gmail message identity metadata could not be read.',
    );
});

test('the Google adapter preserves sanitized HTTP failure semantics', function (
    int $status,
    IntegrationFailureKind $expectedKind,
) {
    $mockHandler = new MockHandler([
        new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['message' => 'sensitive mailbox data'],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $gmail = new GoogleGmail(
        clientId: 'google-client-id',
        clientSecret: 'google-client-secret',
        redirectUri: 'https://money.example.test/settings/connections/gmail/callback',
        httpClient: new Client(['handler' => HandlerStack::create($mockHandler)]),
    );

    try {
        $gmail->messageIdentity('sensitive-access-token', 'sensitive-message-id');
        $this->fail('A failed Gmail request was accepted.');
    } catch (GmailRequestFailed $exception) {
        expect($exception->getMessage())->not->toContain('sensitive')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->httpStatus())->toBe($status)
            ->and(app(ClassifyIntegrationFailure::class)->handle($exception))->toBe($expectedKind);
    }
})->with([
    'authentication' => [401, IntegrationFailureKind::Authentication],
    'authorization' => [403, IntegrationFailureKind::Authorization],
    'schema' => [404, IntegrationFailureKind::Schema],
]);

function gmailBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
