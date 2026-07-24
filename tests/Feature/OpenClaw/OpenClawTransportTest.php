<?php

use App\Contracts\OpenClawHook;
use App\Currency;
use App\Models\OpenClawAuditEvent;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $keyPair = sodium_crypto_sign_keypair();

    $this->openClawPrivateKey = sodium_crypto_sign_secretkey($keyPair);

    config([
        'services.openclaw.capability.key_id' => 'openclaw-service-2026-07',
        'services.openclaw.capability.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        'services.openclaw.capability.agent_id' => 'money-assistant',
        'services.openclaw.capability.account_id' => 'money-assistant-owner',
        'services.openclaw.capability.conversation_id' => 'telegram-owner-123',
        'services.openclaw.capability.owner_sender_id' => 'telegram-owner-123',
        'services.openclaw.capability.rate_limit_per_minute' => 60,
        'services.openclaw.hook.token' => 'outbound-hook-token',
        'services.openclaw.hook.url' => 'http://127.0.0.1:18789/hooks/money-assistant',
    ]);
    RateLimiter::for('openclaw-ingress', fn (): Limit => Limit::perMinute(120));

    $this->callOpenClaw = function (
        array $payload,
        ?string $nonce = null,
        ?string $timestamp = null,
        string $method = 'POST',
        string $path = '/api/openclaw/v1/transport',
        array $signatureOverrides = [],
    ): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= (string) now()->getTimestamp();
        $nonce ??= (string) Str::uuid();
        $signature = sodium_crypto_sign_detached(implode("\n", [
            $signatureOverrides['timestamp'] ?? $timestamp,
            $signatureOverrides['nonce'] ?? $nonce,
            $signatureOverrides['method'] ?? $method,
            $signatureOverrides['path'] ?? $path,
            hash('sha256', $signatureOverrides['body'] ?? $body),
        ]), $this->openClawPrivateKey);

        return $this->call(
            $method,
            $path,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_MONEY_ASSISTANT_KEY_ID' => 'openclaw-service-2026-07',
                'HTTP_X_MONEY_ASSISTANT_TIMESTAMP' => $timestamp,
                'HTTP_X_MONEY_ASSISTANT_NONCE' => $nonce,
                'HTTP_X_MONEY_ASSISTANT_SIGNATURE' => base64_encode($signature),
            ],
            content: $body,
        );
    };

    $this->validPayload = fn (int $transactionId): array => [
        'schema_version' => 1,
        'capability' => 'transaction.read',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-456',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'transaction_id' => $transactionId,
        ],
    ];
});

test('OpenClaw can read one field-minimized owner Transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 12345,
        'currency' => Currency::Usd,
        'kind' => TransactionKind::Purchase,
        'merchant_description' => 'Neighborhood market',
        'revision' => 2,
        'voided_at' => null,
    ]);

    ($this->callOpenClaw)(($this->validPayload)($transaction->id))
        ->assertSuccessful()
        ->assertExactJson([
            'schema_version' => 1,
            'transaction' => [
                'id' => $transaction->id,
                'revision' => 2,
                'occurred_on' => '2026-07-24',
                'amount_minor' => '12345',
                'currency' => 'USD',
                'kind' => 'purchase',
                'merchant_description' => 'Neighborhood market',
                'status' => 'active',
            ],
        ]);

    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($auditEvent->outcome)->toBe('success')
        ->and($auditEvent->http_status)->toBe(200)
        ->and($auditEvent->result_count)->toBe(1)
        ->and($auditEvent->capability)->toBe('transaction.read')
        ->and(json_encode($auditEvent->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('Neighborhood market')
        ->not->toContain('12345');
});

test('the capability transport fails closed for missing credentials and replayed requests', function () {
    $this->postJson('/api/openclaw/v1/transport')->assertUnauthorized();

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);
    $nonce = '01983d79-a780-72f0-bb34-9b4f3f0cf372';

    ($this->callOpenClaw)($payload, nonce: $nonce)->assertSuccessful();
    ($this->callOpenClaw)($payload, nonce: $nonce)->assertConflict();

    $this->travel(300)->seconds();

    ($this->callOpenClaw)(
        $payload,
        nonce: $nonce,
        timestamp: (string) now()->subSeconds(300)->getTimestamp(),
    )->assertConflict();

    expect(OpenClawAuditEvent::query()->pluck('outcome')->all())
        ->toBe(['success', 'replayed_nonce', 'replayed_nonce'])
        ->and(DB::table('open_claw_request_nonces')->count())->toBe(1);
});

test('unauthenticated ingress is rate limited before signature verification', function () {
    RateLimiter::for('openclaw-ingress', fn (): Limit => Limit::perMinute(1));

    $this->postJson('/api/openclaw/v1/transport')->assertUnauthorized();
    $this->postJson('/api/openclaw/v1/transport')->assertTooManyRequests();
    expect(OpenClawAuditEvent::query()->count())->toBe(0);
});

test('authenticated rate-limit failures are audited', function () {
    config(['services.openclaw.capability.rate_limit_per_minute' => 1]);

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);

    ($this->callOpenClaw)($payload)->assertSuccessful();
    ($this->callOpenClaw)($payload)->assertTooManyRequests();

    expect(OpenClawAuditEvent::query()->pluck('outcome')->all())
        ->toBe(['success', 'rate_limited']);
});

test('signature verification binds timestamp nonce method path and exact body', function (array $signatureOverrides) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    ($this->callOpenClaw)(
        ($this->validPayload)($transaction->id),
        signatureOverrides: $signatureOverrides,
    )->assertUnauthorized();

    expect(OpenClawAuditEvent::query()->count())->toBe(0)
        ->and(DB::table('open_claw_request_nonces')->count())->toBe(0);
})->with([
    'timestamp' => [['timestamp' => '1']],
    'nonce' => [['nonce' => 'different-signed-nonce']],
    'method' => [['method' => 'GET']],
    'path' => [['path' => '/api/openclaw/v1/different']],
    'body digest' => [['body' => '{"different":true}']],
]);

test('validly signed stale and malformed authentication claims are rejected and audited', function (
    ?string $nonce,
    string $timestamp,
    string $expectedOutcome,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    ($this->callOpenClaw)(
        ($this->validPayload)($transaction->id),
        nonce: $nonce,
        timestamp: $timestamp,
    )->assertUnauthorized();

    expect(OpenClawAuditEvent::query()->sole()->outcome)->toBe($expectedOutcome);
})->with([
    'stale timestamp' => fn () => [
        null,
        (string) now()->subSeconds(301)->getTimestamp(),
        'stale_signature',
    ],
    'future timestamp' => fn () => [
        null,
        (string) now()->addSeconds(301)->getTimestamp(),
        'stale_signature',
    ],
    'malformed nonce' => fn () => [
        'short',
        (string) now()->getTimestamp(),
        'invalid_request',
    ],
]);

test('unsupported schemas capabilities and expanded request shapes fail closed', function (
    Closure $changePayload,
    string $expectedOutcome,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);
    $changePayload($payload);

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(OpenClawAuditEvent::query()->sole()->outcome)->toBe($expectedOutcome);
})->with([
    'missing schema version' => [
        function (array &$payload): void {
            unset($payload['schema_version']);
        },
        'unsupported_schema',
    ],
    'unsupported schema version' => [
        function (array &$payload): void {
            $payload['schema_version'] = 2;
        },
        'unsupported_schema',
    ],
    'different capability' => [
        function (array &$payload): void {
            $payload['capability'] = 'transaction.list';
        },
        'unsupported_capability',
    ],
    'caller-selected owner' => [
        function (array &$payload): void {
            $payload['input']['owner_id'] = 1;
        },
        'unbound_interaction',
    ],
    'caller-selected fields' => [
        function (array &$payload): void {
            $payload['input']['fields'] = ['*'];
        },
        'unbound_interaction',
    ],
    'unknown top-level field' => [
        function (array &$payload): void {
            $payload['debug'] = true;
        },
        'invalid_request',
    ],
]);

test('malformed signed JSON fails closed and still creates a value-free audit event', function () {
    $body = '{"schema_version":';
    $timestamp = (string) now()->getTimestamp();
    $nonce = (string) Str::uuid();
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
    )->assertUnprocessable();

    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($auditEvent->outcome)->toBe('invalid_request')
        ->and($auditEvent->capability)->toBeNull()
        ->and($auditEvent->schema_version)->toBeNull()
        ->and($auditEvent->result_count)->toBe(0);
});

test('the capability requires a current message from the admitted owner interaction', function (
    Closure $changeInteraction,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);
    $changeInteraction($payload['interaction']);

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(OpenClawAuditEvent::query()->sole()->outcome)->toBe('unbound_interaction');
})->with([
    'wrong interaction kind' => [function (array &$interaction): void {
        $interaction['kind'] = 'scheduled_task';
    }],
    'wrong agent' => [function (array &$interaction): void {
        $interaction['agent_id'] = 'default';
    }],
    'wrong account' => [function (array &$interaction): void {
        $interaction['account_id'] = 'default';
    }],
    'wrong conversation' => [function (array &$interaction): void {
        $interaction['conversation_id'] = 'another-chat';
    }],
    'wrong owner sender' => [function (array &$interaction): void {
        $interaction['owner_sender_id'] = 'another-user';
    }],
    'message older than thirty minutes' => [function (array &$interaction): void {
        $interaction['occurred_at'] = now()->subSeconds(1801)->toIso8601String();
    }],
    'future message' => [function (array &$interaction): void {
        $interaction['occurred_at'] = now()->addSecond()->toIso8601String();
    }],
]);

test('missing owner data and unknown Transactions return a minimized not found response', function (
    bool $createOwner,
) {
    $owner = $createOwner ? User::factory()->create() : null;
    $transactionId = $owner === null
        ? 1
        : Transaction::factory()->for($owner, 'owner')->create()->id + 1;

    ($this->callOpenClaw)(($this->validPayload)($transactionId))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Transaction not found.']);

    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($auditEvent->outcome)->toBe('not_found')
        ->and($auditEvent->result_count)->toBe(0);
})->with([
    'no owner' => [false],
    'unknown Transaction' => [true],
]);

test('authenticated calls fail closed when their audit cannot be appended', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reject_open_claw_audit_event_insert() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'Audit unavailable.' USING ERRCODE = '23514';
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER open_claw_audit_events_reject_insert
        BEFORE INSERT ON open_claw_audit_events
        FOR EACH ROW EXECUTE FUNCTION reject_open_claw_audit_event_insert();
        SQL);

    try {
        ($this->callOpenClaw)(($this->validPayload)($transaction->id))
            ->assertServerError()
            ->assertJsonMissing(['amount_minor' => (string) $transaction->amount_minor]);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS open_claw_audit_events_reject_insert ON open_claw_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_open_claw_audit_event_insert()');
    }
});

test('OpenClaw audit events are database-enforced append-only records', function (string $operation) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    ($this->callOpenClaw)(($this->validPayload)($transaction->id))->assertSuccessful();
    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect(fn () => DB::transaction(function () use ($operation, $auditEvent): void {
        if ($operation === 'update') {
            DB::table('open_claw_audit_events')
                ->where('id', $auditEvent->id)
                ->update(['outcome' => 'invalid_request']);

            return;
        }

        DB::table('open_claw_audit_events')->where('id', $auditEvent->id)->delete();
    }))->toThrow(QueryException::class);
})->with(['update', 'delete']);

test('OpenClaw audit constraints reject unsupported or value-retaining outcomes', function (
    array $invalidAttributes,
) {
    expect(fn () => DB::transaction(fn () => DB::table('open_claw_audit_events')->insert([
        'occurred_at' => now(),
        'service_key_id' => 'openclaw-service-2026-07',
        'schema_version' => 1,
        'capability' => 'transaction.read',
        'outcome' => 'success',
        'http_status' => 200,
        'nonce_digest' => str_repeat('a', 64),
        'request_digest' => str_repeat('b', 64),
        'interaction_digest' => str_repeat('c', 64),
        'resource_type' => 'transaction',
        'result_count' => 1,
        ...$invalidAttributes,
    ])))->toThrow(QueryException::class);
})->with([
    'unsupported outcome' => [['outcome' => 'returned_amount_12345']],
    'unbounded result count' => [['result_count' => 2]],
    'invalid status' => [['http_status' => 700]],
]);

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
