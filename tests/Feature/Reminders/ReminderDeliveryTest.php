<?php

use App\Actions\Reminders\DeliverReminderDelivery;
use App\Actions\Reminders\EnqueueDueReminderDeliveries;
use App\Actions\Reminders\ScheduleReminder;
use App\Contracts\OpenClawHook;
use App\Jobs\DeliverReminder;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'services.openclaw.hook.token' => 'outbound-hook-token',
        'services.openclaw.hook.url' => 'http://127.0.0.1:19789/hooks/money-assistant',
    ]);
});

test('a due Reminder is persisted with one outbox event before external delivery', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:00:00 UTC'));
    Http::preventStrayRequests();
    $owner = User::factory()->create();

    $reminder = app(ScheduleReminder::class)->handle(
        owner: $owner,
        subject: 'Review uncategorized Transactions',
        scheduledFor: now(),
    );

    app(EnqueueDueReminderDeliveries::class)->handle();
    app(EnqueueDueReminderDeliveries::class)->handle();

    $persistedReminder = Reminder::query()->sole();
    $delivery = ReminderDelivery::query()->sole();

    expect($persistedReminder->is($reminder))->toBeTrue()
        ->and($persistedReminder->owner->is($owner))->toBeTrue()
        ->and($persistedReminder->subject)->toBe('Review uncategorized Transactions')
        ->and($persistedReminder->scheduled_for->toIso8601String())->toBe('2026-07-26T15:00:00+00:00')
        ->and($delivery->reminder->is($reminder))->toBeTrue()
        ->and(Str::isUuid($delivery->id))->toBeTrue()
        ->and($delivery->event_type)->toBe('reminder.due')
        ->and($delivery->occurred_at->toIso8601String())->toBe('2026-07-26T15:00:00+00:00')
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->accepted_at)->toBeNull()
        ->and($delivery->delivered_at)->toBeNull()
        ->and($delivery->terminal_at)->toBeNull();

    Http::assertNothingSent();
});

test('a duplicate worker execution produces one accepted owner-visible digest', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:05:00 UTC'));
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:19789/hooks/money-assistant' => Http::response(status: 202),
    ]);
    $delivery = ReminderDelivery::factory()->create([
        'occurred_at' => CarbonImmutable::parse('2026-07-26 15:00:00 UTC'),
    ]);
    $job = new DeliverReminder($delivery->id);

    $job->handle(app(DeliverReminderDelivery::class));
    $job->handle(app(DeliverReminderDelivery::class));

    $delivery->refresh();

    expect($delivery->attempt_count)->toBe(1)
        ->and($delivery->accepted_at?->toIso8601String())->toBe('2026-07-26T15:05:00+00:00')
        ->and($delivery->delivered_at)->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $delivery->id)
        && $request->data() === [
            'event_id' => $delivery->id,
            'event_type' => 'reminder.due',
            'occurred_at' => '2026-07-26T15:00:00Z',
        ]);
});

test('transient hook failures use bounded persisted retries before terminal handling', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:00:00 UTC'));
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:19789/hooks/money-assistant' => Http::response(status: 503),
    ]);
    $delivery = ReminderDelivery::factory()->create([
        'id' => '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    ]);
    $action = app(DeliverReminderDelivery::class);

    $action->handle($delivery->id);
    $delivery->refresh();
    $firstRetryAt = $delivery->next_attempt_at;

    expect($delivery->attempt_count)->toBe(1)
        ->and($delivery->terminal_at)->toBeNull()
        ->and($firstRetryAt)->not->toBeNull()
        ->and($firstRetryAt?->betweenIncluded(now()->addSeconds(60), now()->addSeconds(90)))->toBeTrue();

    $action->handle($delivery->id);
    Http::assertSentCount(3);

    $this->travelTo($firstRetryAt);
    $action->handle($delivery->id);
    $delivery->refresh();
    $secondRetryAt = $delivery->next_attempt_at;

    expect($delivery->attempt_count)->toBe(2)
        ->and($secondRetryAt?->betweenIncluded(now()->addSeconds(120), now()->addSeconds(150)))->toBeTrue();

    $this->travelTo($secondRetryAt);
    $action->handle($delivery->id);
    $delivery->refresh();

    expect($delivery->attempt_count)->toBe(3)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->terminal_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($delivery->terminal_reason)->toBe('retry_exhausted')
        ->and($delivery->last_error_code)->toBe('http_503');

    $action->handle($delivery->id);
    Http::assertSentCount(9);
    expect(collect(Http::recorded())->every(
        fn (array $exchange): bool => $exchange[0]->hasHeader('Idempotency-Key', $delivery->id),
    ))->toBeTrue();
});

test('a stale failed worker cannot overwrite a later accepted delivery', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:00:00 UTC'));
    $delivery = ReminderDelivery::factory()->create();
    $hook = new class implements OpenClawHook
    {
        public function dispatch(string $eventId, string $eventType, DateTimeInterface $occurredAt): void
        {
            ReminderDelivery::query()->whereKey($eventId)->update([
                'accepted_at' => now(),
                'claimed_at' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

            throw new RuntimeException('The stale worker observed a late failure.');
        }
    };

    (new DeliverReminderDelivery($hook))->handle($delivery->id);
    $delivery->refresh();

    expect($delivery->accepted_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($delivery->terminal_at)->toBeNull()
        ->and($delivery->terminal_reason)->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull();
});

test('a resolved Reminder cannot be delivered by an already queued worker', function () {
    Http::preventStrayRequests();
    $delivery = ReminderDelivery::factory()->create();
    $delivery->reminder->forceFill(['resolved_at' => now()])->save();

    app(DeliverReminderDelivery::class)->handle($delivery->id);
    $delivery->refresh();

    expect($delivery->attempt_count)->toBe(0)
        ->and($delivery->terminal_at)->not->toBeNull()
        ->and($delivery->terminal_reason)->toBe('deterministic_failure')
        ->and($delivery->last_error_code)->toBe('reminder_inactive');
    Http::assertNothingSent();
});

test('the Laravel scheduler queues each pending outbox delivery once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:00:00 UTC'));
    config()->set('cache.default', 'array');
    Queue::fake();
    Schedule::useCache('array');
    $reminder = Reminder::factory()->create(['scheduled_for' => now()]);

    $this->artisan('schedule:run')->assertSuccessful();
    $this->artisan('schedule:run')->assertSuccessful();

    $delivery = ReminderDelivery::query()->sole();

    expect($delivery->reminder->is($reminder))->toBeTrue()
        ->and($delivery->queued_at)->not->toBeNull();
    Queue::assertPushed(
        DeliverReminder::class,
        fn (DeliverReminder $job): bool => $job->deliveryId === $delivery->id,
    );
    Queue::assertPushed(DeliverReminder::class, 1);
});

test('deterministic hook failures terminate without an outbox retry', function (
    int $status,
    string $terminalReason,
) {
    $this->travelTo(CarbonImmutable::parse('2026-07-26 15:00:00 UTC'));
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:19789/hooks/money-assistant' => Http::response(status: $status),
    ]);
    $delivery = ReminderDelivery::factory()->create();

    app(DeliverReminderDelivery::class)->handle($delivery->id);
    $delivery->refresh();

    expect($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->terminal_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($delivery->terminal_reason)->toBe($terminalReason)
        ->and($delivery->last_error_code)->toBe("http_{$status}");
    Http::assertSentCount(1);
})->with([
    'authentication' => [401, 'authorization_rejected'],
    'authorization' => [403, 'authorization_rejected'],
    'validation' => [422, 'validation_rejected'],
]);
