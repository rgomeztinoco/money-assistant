<?php

use App\Contracts\OpenClawHook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = sodium_crypto_sign_keypair();

    $this->openClawPrivateKey = sodium_crypto_sign_secretkey($keyPair);

    config([
        'services.openclaw.capability.key_id' => 'openclaw-service-2026-07',
        'services.openclaw.capability.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        'services.openclaw.hook.token' => 'outbound-hook-token',
        'services.openclaw.hook.url' => 'http://127.0.0.1:18789/hooks/money-assistant',
    ]);
});

test('OpenClaw can authenticate a private Laravel capability transport probe', function () {
    $body = '{}';
    $timestamp = (string) now()->getTimestamp();
    $nonce = '01983d79-a780-72f0-bb34-9b4f3f0cf372';
    $signature = sodium_crypto_sign_detached(implode("\n", [
        $timestamp,
        $nonce,
        'POST',
        '/api/openclaw/v1/transport',
        hash('sha256', $body),
    ]), $this->openClawPrivateKey);

    $this->call(
        'POST',
        '/api/openclaw/v1/transport',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MONEY_ASSISTANT_KEY_ID' => 'openclaw-service-2026-07',
            'HTTP_X_MONEY_ASSISTANT_TIMESTAMP' => $timestamp,
            'HTTP_X_MONEY_ASSISTANT_NONCE' => $nonce,
            'HTTP_X_MONEY_ASSISTANT_SIGNATURE' => base64_encode($signature),
        ],
        content: $body,
    )->assertSuccessful()->assertExactJson(['status' => 'ok']);
});

test('the capability transport fails closed for missing credentials and replayed requests', function () {
    $this->postJson('/api/openclaw/v1/transport')->assertUnauthorized();

    $body = '{}';
    $timestamp = (string) now()->getTimestamp();
    $nonce = '01983d79-a780-72f0-bb34-9b4f3f0cf372';
    $signature = base64_encode(sodium_crypto_sign_detached(implode("\n", [
        $timestamp,
        $nonce,
        'POST',
        '/api/openclaw/v1/transport',
        hash('sha256', $body),
    ]), $this->openClawPrivateKey));
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_MONEY_ASSISTANT_KEY_ID' => 'openclaw-service-2026-07',
        'HTTP_X_MONEY_ASSISTANT_TIMESTAMP' => $timestamp,
        'HTTP_X_MONEY_ASSISTANT_NONCE' => $nonce,
        'HTTP_X_MONEY_ASSISTANT_SIGNATURE' => $signature,
    ];

    $this->call('POST', '/api/openclaw/v1/transport', server: $server, content: $body)
        ->assertSuccessful();
    $this->call('POST', '/api/openclaw/v1/transport', server: $server, content: $body)
        ->assertConflict();

    $this->travel(300)->seconds();

    $this->call('POST', '/api/openclaw/v1/transport', server: $server, content: $body)
        ->assertConflict();
});

test('Laravel sends only a minimal event through the fixed mapped hook', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18789/hooks/money-assistant' => Http::response(status: 202),
    ]);

    app(OpenClawHook::class)->dispatch(
        eventId: '01J3AGV2C8ZQJ9W7K1M4B5N6P7',
        eventType: 'transport.probe',
        occurredAt: CarbonImmutable::parse('2026-07-24T15:00:00Z'),
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:18789/hooks/money-assistant'
        && $request->hasHeader('Authorization', 'Bearer outbound-hook-token')
        && $request->data() === [
            'event_id' => '01J3AGV2C8ZQJ9W7K1M4B5N6P7',
            'event_type' => 'transport.probe',
            'occurred_at' => '2026-07-24T15:00:00Z',
        ]
    );
});

test('the mapped hook retries transient failures', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18789/hooks/money-assistant' => Http::sequence()
            ->pushStatus(503)
            ->pushStatus(202),
    ]);

    app(OpenClawHook::class)->dispatch(
        eventId: '01J3AGV2C8ZQJ9W7K1M4B5N6P7',
        eventType: 'transport.probe',
        occurredAt: CarbonImmutable::parse('2026-07-24T15:00:00Z'),
    );

    Http::assertSentCount(2);
});

test('the mapped hook does not retry deterministic failures', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18789/hooks/money-assistant' => Http::response(status: 422),
    ]);

    expect(fn () => app(OpenClawHook::class)->dispatch(
        eventId: '01J3AGV2C8ZQJ9W7K1M4B5N6P8',
        eventType: 'transport.probe',
        occurredAt: CarbonImmutable::parse('2026-07-24T15:00:00Z'),
    ))->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
