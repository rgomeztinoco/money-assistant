<?php

use App\Actions\Reminders\EnqueueDueReminderDeliveries;
use App\Actions\Reminders\ResolveReminder;
use App\Actions\Reminders\RespondToReminder;
use App\Actions\Reminders\ScheduleReminder;
use App\Models\OpenClawAuditEvent;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\ReminderLifecycleEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
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
    ]);
    RateLimiter::for('openclaw-ingress', fn (): Limit => Limit::perMinute(120));

    $this->callOpenClaw = function (array $payload): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $signature = sodium_crypto_sign_detached(implode("\n", [
            $timestamp,
            $nonce,
            'POST',
            '/api/openclaw/v1/transport',
            hash('sha256', $body),
        ]), $this->openClawPrivateKey);

        return $this->call(
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
        );
    };

    $this->ownerReminderResponse = fn (array $input): array => [
        'schema_version' => 1,
        'capability' => 'reminder.respond',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-reminder-response-456',
            'occurred_at' => now()->format('Y-m-d\TH:i:s\Z'),
        ],
        'input' => $input,
    ];
});

test('a mapped-hook event reads only its current Reminder and distinct delivery state', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:05:00 UTC'));
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create([
        'subject' => 'Review uncategorized Transactions',
        'scheduled_for' => CarbonImmutable::parse('2026-07-26 15:00:00 UTC'),
    ]);
    $delivery = ReminderDelivery::factory()->for($reminder)->create([
        'occurred_at' => CarbonImmutable::parse('2026-07-26 15:00:00 UTC'),
        'accepted_at' => CarbonImmutable::parse('2026-07-26 15:00:05 UTC'),
    ]);

    ($this->callOpenClaw)([
        'schema_version' => 1,
        'capability' => 'reminder.read',
        'interaction' => [
            'kind' => 'money_assistant_event',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => $delivery->id,
            'occurred_at' => '2026-07-26T15:05:00Z',
        ],
        'input' => ['event_id' => $delivery->id],
    ])->assertSuccessful()->assertExactJson([
        'schema_version' => 1,
        'reminder' => [
            'id' => $reminder->id,
            'subject' => 'Review uncategorized Transactions',
            'scheduled_for' => '2026-07-26T15:00:00+00:00',
            'revision' => 1,
            'acknowledged_at' => null,
            'snoozed_until' => null,
            'dismissed_at' => null,
            'resolved_at' => null,
        ],
        'delivery' => [
            'event_id' => $delivery->id,
            'hook_accepted_at' => '2026-07-26T15:00:05+00:00',
            'channel_delivered_at' => null,
        ],
    ]);
});

test('an old event identifier cannot create fresh Reminder authority', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:35:01 UTC'));
    $reminder = Reminder::factory()->create();
    $delivery = ReminderDelivery::factory()->for($reminder)->create([
        'occurred_at' => CarbonImmutable::parse('2026-07-26 15:05:00 UTC'),
    ]);
    $payload = [
        'schema_version' => 1,
        'capability' => 'reminder.read',
        'interaction' => [
            'kind' => 'money_assistant_event',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => $delivery->id,
            'occurred_at' => '2026-07-26T15:35:01Z',
        ],
        'input' => ['event_id' => $delivery->id],
    ];

    ($this->callOpenClaw)($payload)->assertNotFound();

    $payload['capability'] = 'reminder.delivery.record';
    ($this->callOpenClaw)($payload)->assertNotFound();
    expect($delivery->fresh()->delivered_at)->toBeNull();
});

test('a delayed outbox event gains fresh authority when delivery starts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 16:05:00 UTC'));
    $reminder = Reminder::factory()->create();
    $delivery = ReminderDelivery::factory()->for($reminder)->create([
        'occurred_at' => CarbonImmutable::parse('2026-07-26 15:00:00 UTC'),
        'last_attempted_at' => now(),
    ]);

    ($this->callOpenClaw)([
        'schema_version' => 1,
        'capability' => 'reminder.read',
        'interaction' => [
            'kind' => 'money_assistant_event',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => $delivery->id,
            'occurred_at' => '2026-07-26T16:05:00Z',
        ],
        'input' => ['event_id' => $delivery->id],
    ])->assertSuccessful()->assertJsonPath('delivery.event_id', $delivery->id);
});

test('channel delivery is recorded idempotently without implying an owner response', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:05:00 UTC'));
    $reminder = Reminder::factory()->create();
    $delivery = ReminderDelivery::factory()->for($reminder)->create([
        'accepted_at' => CarbonImmutable::parse('2026-07-26 15:00:05 UTC'),
    ]);
    $payload = [
        'schema_version' => 1,
        'capability' => 'reminder.delivery.record',
        'interaction' => [
            'kind' => 'money_assistant_event',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => $delivery->id,
            'occurred_at' => '2026-07-26T15:05:00Z',
        ],
        'input' => ['event_id' => $delivery->id],
    ];

    ($this->callOpenClaw)($payload)
        ->assertSuccessful()
        ->assertJsonPath('delivery.channel_delivered_at', '2026-07-26T15:05:00+00:00');

    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:06:00 UTC'));
    $payload['interaction']['occurred_at'] = '2026-07-26T15:06:00Z';
    ($this->callOpenClaw)($payload)
        ->assertSuccessful()
        ->assertJsonPath('delivery.channel_delivered_at', '2026-07-26T15:05:00+00:00');

    $reminder->refresh();

    expect($reminder->acknowledged_at)->toBeNull()
        ->and($reminder->snoozed_until)->toBeNull()
        ->and($reminder->dismissed_at)->toBeNull()
        ->and($reminder->resolved_at)->toBeNull();
});

test('acknowledgement is idempotent and cannot be broadened into dismissal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:10:00 UTC'));
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create();
    $idempotencyKey = '01983d79-a780-72f0-bb34-9b4f3f0cf391';
    $acknowledgement = ($this->ownerReminderResponse)([
        'idempotency_key' => $idempotencyKey,
        'reminder_id' => $reminder->id,
        'action' => 'acknowledge',
    ]);

    ($this->callOpenClaw)($acknowledgement)
        ->assertSuccessful()
        ->assertJsonPath('reminder.revision', 2)
        ->assertJsonPath('reminder.acknowledged_at', '2026-07-26T15:10:00+00:00')
        ->assertJsonPath('reminder.dismissed_at', null);
    ($this->callOpenClaw)($acknowledgement)
        ->assertSuccessful()
        ->assertJsonPath('reminder.revision', 2);

    $dismissal = $acknowledgement;
    $dismissal['input']['action'] = 'dismiss';
    ($this->callOpenClaw)($dismissal)->assertConflict();

    $reminder->refresh();

    expect(ReminderLifecycleEvent::query()->count())->toBe(1)
        ->and($reminder->acknowledged_at?->toIso8601String())->toBe('2026-07-26T15:10:00+00:00')
        ->and($reminder->dismissed_at)->toBeNull()
        ->and($reminder->resolved_at)->toBeNull();
});

test('a Reminder response rolls back when its protected audit cannot be written', function () {
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create();

    expect(fn () => app(RespondToReminder::class)->handle(
        owner: $owner,
        serviceKeyId: str_repeat('x', 129),
        schemaVersion: 1,
        interactionDigest: str_repeat('a', 64),
        nonceDigest: str_repeat('b', 64),
        requestDigest: str_repeat('c', 64),
        idempotencyKey: '01983d79-a780-72f0-bb34-9b4f3f0cf399',
        reminderId: $reminder->id,
        action: 'acknowledge',
    ))->toThrow(QueryException::class);

    $reminder->refresh();

    expect($reminder->acknowledged_at)->toBeNull()
        ->and($reminder->revision)->toBe(1)
        ->and(ReminderLifecycleEvent::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->count())->toBe(0);
});

test('snoozing defers the same Reminder into a new delivery occurrence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:10:00 UTC'));
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create([
        'scheduled_for' => CarbonImmutable::parse('2026-07-26 15:00:00 UTC'),
    ]);
    $firstDelivery = ReminderDelivery::factory()->for($reminder)->create([
        'scheduled_for' => CarbonImmutable::parse('2026-07-26 15:00:00 UTC'),
    ]);
    $snooze = ($this->ownerReminderResponse)([
        'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf392',
        'reminder_id' => $reminder->id,
        'action' => 'snooze',
        'snoozed_until' => '2026-07-26T16:00:00Z',
    ]);

    ($this->callOpenClaw)($snooze)
        ->assertSuccessful()
        ->assertJsonPath('reminder.snoozed_until', '2026-07-26T16:00:00+00:00')
        ->assertJsonPath('reminder.acknowledged_at', null)
        ->assertJsonPath('reminder.dismissed_at', null)
        ->assertJsonPath('reminder.resolved_at', null);

    app(EnqueueDueReminderDeliveries::class)->handle();
    expect($reminder->deliveries()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-07-26 16:00:00 UTC'));
    app(EnqueueDueReminderDeliveries::class)->handle();

    $secondDelivery = $reminder->deliveries()->whereKeyNot($firstDelivery->id)->sole();

    expect($secondDelivery->reminder_id)->toBe($reminder->id)
        ->and($secondDelivery->scheduled_for->toIso8601String())->toBe('2026-07-26T16:00:00+00:00')
        ->and($secondDelivery->id)->not->toBe($firstDelivery->id);
});

test('dismissal closes only its Reminder occurrence without resolving it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:10:00 UTC'));
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create(['scheduled_for' => now()]);

    ($this->callOpenClaw)(($this->ownerReminderResponse)([
        'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf393',
        'reminder_id' => $reminder->id,
        'action' => 'dismiss',
    ]))->assertSuccessful();

    app(EnqueueDueReminderDeliveries::class)->handle();
    $reminder->refresh();

    $laterOccurrence = app(ScheduleReminder::class)->handle(
        owner: $owner,
        subject: $reminder->subject,
        scheduledFor: now(),
    );
    app(EnqueueDueReminderDeliveries::class)->handle();

    expect($reminder->dismissed_at?->toIso8601String())->toBe('2026-07-26T15:10:00+00:00')
        ->and($reminder->acknowledged_at)->toBeNull()
        ->and($reminder->resolved_at)->toBeNull()
        ->and($reminder->deliveries()->count())->toBe(0)
        ->and($laterOccurrence->deliveries()->count())->toBe(1);
});

test('an offered domain action resolves a Reminder independently and idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:10:00 UTC'));
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create(['scheduled_for' => now()]);
    $action = app(ResolveReminder::class);

    $action->handle(
        owner: $owner,
        reminderId: $reminder->id,
        domainAction: 'categorization.completed',
        idempotencyKey: '01983d79-a780-72f0-bb34-9b4f3f0cf394',
    );
    $action->handle(
        owner: $owner,
        reminderId: $reminder->id,
        domainAction: 'categorization.completed',
        idempotencyKey: '01983d79-a780-72f0-bb34-9b4f3f0cf394',
    );

    app(EnqueueDueReminderDeliveries::class)->handle();
    $reminder->refresh();
    $event = ReminderLifecycleEvent::query()->sole();

    expect($reminder->resolved_at?->toIso8601String())->toBe('2026-07-26T15:10:00+00:00')
        ->and($reminder->dismissed_at)->toBeNull()
        ->and($reminder->acknowledged_at)->toBeNull()
        ->and($event->action)->toBe('resolved')
        ->and($event->domain_action)->toBe('categorization.completed')
        ->and($reminder->deliveries()->count())->toBe(0);
});
