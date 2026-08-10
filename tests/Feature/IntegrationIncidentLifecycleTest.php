<?php

use App\Actions\Integrations\AcknowledgeIntegrationIncident;
use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Actions\Integrations\ReadActionableIntegrationIncidents;
use App\Actions\Integrations\RecordIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationRecovery;
use App\Actions\Reporting\SeedDailyExchangeRateFromBcrpData;
use App\Contracts\BcrpData;
use App\Exceptions\StaleDailyExchangeRateRevision;
use App\IntegrationFailureKind;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Jobs\DeliverReminder;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\ReminderDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

test('a continuous transient failure becomes visible after fifteen minutes and parks after one day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00 UTC'));
    $owner = User::factory()->create();
    $recordFailure = app(RecordIntegrationFailure::class);

    $decision = $recordFailure->handle(
        owner: $owner,
        integration: IntegrationService::Bcrp,
        workType: IntegrationWorkType::DailyExchangeRateSeed,
        workId: '42',
        sourceIdentity: 'daily-exchange-rate:2026-08-01',
        failureKind: IntegrationFailureKind::Transient,
        errorCode: 'connection_failed',
    );

    expect($decision->shouldRetry)->toBeTrue()
        ->and($decision->nextAttemptAt)->not->toBeNull()
        ->and($decision->nextAttemptAt?->betweenIncluded(
            now()->addMinute(),
            now()->addSeconds(90),
        ))->toBeTrue()
        ->and(app(ReadActionableIntegrationIncidents::class)->handle($owner))->toBeEmpty();

    $this->travel(15)->minutes();

    expect(app(ReadActionableIntegrationIncidents::class)->handle($owner))->toHaveCount(1);

    $this->travelTo(CarbonImmutable::parse('2026-08-02 10:00:00 UTC'));

    $decision = $recordFailure->handle(
        owner: $owner,
        integration: IntegrationService::Bcrp,
        workType: IntegrationWorkType::DailyExchangeRateSeed,
        workId: '42',
        sourceIdentity: 'daily-exchange-rate:2026-08-01',
        failureKind: IntegrationFailureKind::Transient,
        errorCode: 'connection_failed',
    );

    expect($decision->shouldRetry)->toBeFalse()
        ->and($decision->nextAttemptAt)->toBeNull()
        ->and($decision->incident->parked_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('acknowledgement and recovery each remove an actionable incident', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00 UTC'));
    $owner = User::factory()->create();
    $recordFailure = app(RecordIntegrationFailure::class);
    $incident = $recordFailure->handle(
        owner: $owner,
        integration: IntegrationService::OpenClaw,
        workType: IntegrationWorkType::ReminderDelivery,
        workId: 'delivery-42',
        sourceIdentity: 'reminder-delivery:delivery-42',
        failureKind: IntegrationFailureKind::Transient,
        errorCode: 'http_503',
    )->incident;

    $this->travel(15)->minutes();

    app(AcknowledgeIntegrationIncident::class)->handle($owner, $incident->id);

    expect(app(ReadActionableIntegrationIncidents::class)->handle($owner))->toBeEmpty();

    $recordFailure->handle(
        owner: $owner,
        integration: IntegrationService::OpenClaw,
        workType: IntegrationWorkType::ReminderDelivery,
        workId: 'delivery-42',
        sourceIdentity: 'reminder-delivery:delivery-42',
        failureKind: IntegrationFailureKind::Transient,
        errorCode: 'http_503',
    );
    app(RecordIntegrationRecovery::class)->handle(
        owner: $owner,
        integration: IntegrationService::OpenClaw,
        workType: IntegrationWorkType::ReminderDelivery,
        workId: 'delivery-42',
    );

    expect($incident->fresh()->recovered_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and(app(ReadActionableIntegrationIncidents::class)->handle($owner))->toBeEmpty();
});

test('BCRP transport work follows the shared retry window and parks for replay', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-08-01',
    ]);
    app()->instance(BcrpData::class, new class implements BcrpData
    {
        public function findObservation(CarbonImmutable $applicableOn): never
        {
            throw new ConnectionException('BCRPData is temporarily unavailable.');
        }
    });
    $action = app(SeedDailyExchangeRateFromBcrpData::class);

    $action->handle($seedRequest->id);

    $incident = $seedRequest->owner->integrationIncidents()->sole();
    expect($seedRequest->fresh()->next_attempt_at?->toIso8601String())
        ->toBe($incident->next_attempt_at?->toIso8601String())
        ->and($incident->failure_kind)->toBe(IntegrationFailureKind::Transient)
        ->and($incident->parked_at)->toBeNull();

    $this->travelTo($incident->retry_until);
    $action->handle($seedRequest->id);

    expect($seedRequest->fresh()->retrieval_failed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($incident->fresh()->parked_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('the owner replays parked work once with its original source identity', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00 UTC'));
    Queue::fake();
    $delivery = ReminderDelivery::factory()->create();
    $owner = $delivery->reminder->owner;
    $recordFailure = app(RecordIntegrationFailure::class);
    $incident = $recordFailure->handle(
        owner: $owner,
        integration: IntegrationService::OpenClaw,
        workType: IntegrationWorkType::ReminderDelivery,
        workId: $delivery->id,
        sourceIdentity: 'openclaw:reminder.due:'.$delivery->id,
        failureKind: IntegrationFailureKind::Transient,
        errorCode: 'http_503',
    )->incident;
    $this->travelTo($incident->retry_until);
    $recordFailure->handle(
        owner: $owner,
        integration: IntegrationService::OpenClaw,
        workType: IntegrationWorkType::ReminderDelivery,
        workId: $delivery->id,
        sourceIdentity: 'openclaw:reminder.due:'.$delivery->id,
        failureKind: IntegrationFailureKind::Transient,
        errorCode: 'http_503',
    );
    $delivery->forceFill([
        'next_attempt_at' => null,
        'terminal_at' => now(),
        'terminal_reason' => 'retry_exhausted',
        'last_error_code' => 'http_503',
    ])->save();

    $this->actingAs($owner)
        ->post(route('integration_incidents.replay.store', $incident))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($incident->fresh())
        ->source_identity->toBe('openclaw:reminder.due:'.$delivery->id)
        ->replay_count->toBe(1)
        ->parked_at->toBeNull()
        ->and($delivery->fresh())
        ->id->toBe($delivery->id)
        ->terminal_at->toBeNull()
        ->queued_at->not->toBeNull();
    Queue::assertPushed(
        DeliverReminder::class,
        fn (DeliverReminder $job): bool => $job->deliveryId === $delivery->id,
    );
    Queue::assertPushed(DeliverReminder::class, 1);

    $this->post(route('integration_incidents.replay.store', $incident))
        ->assertSessionHasErrors('replay');
    Queue::assertPushed(DeliverReminder::class, 1);
});

test('deterministic failure classes park without entering the retry schedule', function (
    Closure $failure,
    IntegrationFailureKind $expectedKind,
) {
    $owner = User::factory()->create();
    $kind = app(ClassifyIntegrationFailure::class)->handle($failure());

    $decision = app(RecordIntegrationFailure::class)->handle(
        owner: $owner,
        integration: IntegrationService::OpenClaw,
        workType: IntegrationWorkType::ReminderDelivery,
        workId: 'delivery-42',
        sourceIdentity: 'openclaw:reminder.due:delivery-42',
        failureKind: $kind,
        errorCode: 'deterministic_failure',
    );

    expect($kind)->toBe($expectedKind)
        ->and($decision->shouldRetry)->toBeFalse()
        ->and($decision->nextAttemptAt)->toBeNull()
        ->and($decision->incident->parked_at)->not->toBeNull();
})->with([
    'authentication' => [fn (): Throwable => new AuthenticationException, IntegrationFailureKind::Authentication],
    'authorization' => [fn (): Throwable => new AuthorizationException, IntegrationFailureKind::Authorization],
    'schema' => [fn (): Throwable => new UnexpectedValueException, IntegrationFailureKind::Schema],
    'concurrency' => [fn (): Throwable => new StaleDailyExchangeRateRevision, IntegrationFailureKind::Concurrency],
    'validation' => [fn (): Throwable => ValidationException::withMessages(['field' => 'Invalid.']), IntegrationFailureKind::Validation],
]);
