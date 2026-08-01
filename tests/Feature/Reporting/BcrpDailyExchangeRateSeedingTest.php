<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\Actions\Reporting\DiscoverMissingDailyExchangeRates;
use App\Actions\Reporting\SeedDailyExchangeRateFromBcrpData;
use App\Contracts\BcrpData;
use App\Currency;
use App\IntegrationFailureKind;
use App\Integrations\BcrpData\BcrpExchangeRateObservation;
use App\Jobs\SeedDailyExchangeRate;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
});

test('an exact BCRP interbank sell observation seeds a missing Daily Exchange Rate with distinct provenance', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-24',
        'currency' => Currency::Usd,
    ]);
    Http::fake([
        'https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-17/2026-07-24/esp' => Http::response(bcrpPayload([
            ['name' => '23.Jul.26', 'values' => ['3.40378571428571']],
            ['name' => '24.Jul.26', 'values' => ['3.39914285714286']],
        ])),
    ]);

    app(DiscoverMissingDailyExchangeRates::class)->handle();
    $seedRequest = DailyExchangeRateSeedRequest::query()->sole();
    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);
    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);

    $rate = DailyExchangeRate::query()->sole();

    expect($rate->applicable_on->toDateString())->toBe('2026-07-24')
        ->and($rate->pen_per_usd_scaled)->toBe(3_399_143)
        ->and($rate->owner_managed_at)->toBeNull()
        ->and($rate->source)->toBe('bcrp_data')
        ->and($rate->source_series)->toBe('PD04638PD')
        ->and($rate->source_observed_on->toDateString())->toBe('2026-07-24')
        ->and($rate->source_retrieved_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($rate->source_value)->toBe('3.39914285714286')
        ->and($rate->source_precision)->toBe(3)
        ->and($seedRequest->fresh()->completed_at)->not->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-17/2026-07-24/esp');

    $this->actingAs($owner)
        ->get(route('daily_exchange_rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rates.0.applicable_on', '2026-07-24')
            ->where('rates.0.source.label', 'BCRP interbank sell')
            ->where('rates.0.source.attribution', 'Banco Central de Reserva del Peru')
            ->where('rates.0.source.series', 'PD04638PD')
            ->where('rates.0.source.observed_on', '2026-07-24')
            ->where('rates.0.source.retrieved_at', now()->toIso8601String())
            ->where('rates.0.source.value', '3.39914285714286')
            ->where('rates.0.source.precision', 3));
});

test('the latest valid prior BCRP observation within seven calendar days seeds a non-business date without inventing precision', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-26',
    ]);
    Http::fake([
        'https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-19/2026-07-26/esp' => Http::response(bcrpPayload([
            ['name' => '19.Jul.26', 'values' => ['3.500']],
            ['name' => '24.Jul.26', 'values' => ['3.545']],
            ['name' => '25.Jul.26', 'values' => ['n.d.']],
        ])),
    ]);

    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);

    $rate = DailyExchangeRate::query()->sole();

    expect($rate->applicable_on->toDateString())->toBe('2026-07-26')
        ->and($rate->source_observed_on->toDateString())->toBe('2026-07-24')
        ->and($rate->pen_per_usd_scaled)->toBe(3_545_000)
        ->and($rate->source_value)->toBe('3.545')
        ->and($rate->source_precision)->toBe(3);
});

test('a recent missing business-day observation is retried before a prior observation may be used', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-27',
    ]);
    Http::fake([
        'https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-20/2026-07-27/esp' => Http::response(bcrpPayload([
            ['name' => '24.Jul.26', 'values' => ['3.545']],
            ['name' => '27.Jul.26', 'values' => ['n.d.']],
        ])),
    ]);

    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);
    $seedRequest->refresh();

    expect(DailyExchangeRate::query()->count())->toBe(0)
        ->and($seedRequest->attempt_count)->toBe(1)
        ->and($seedRequest->next_attempt_at?->betweenIncluded(now()->addHour(), now()->addHour()->addMinutes(10)))->toBeTrue()
        ->and($seedRequest->owner_entry_required_at)->toBeNull()
        ->and($seedRequest->last_error_code)->toBe('recent_observation_unavailable');

    $this->travelTo($seedRequest->next_attempt_at);
    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);
    $this->travelTo($seedRequest->fresh()->next_attempt_at);
    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);
    $seedRequest->refresh();

    $rate = DailyExchangeRate::query()->sole();

    expect($rate->source_observed_on->toDateString())->toBe('2026-07-24')
        ->and($seedRequest->attempt_count)->toBe(3)
        ->and($seedRequest->completed_at)->not->toBeNull()
        ->and($seedRequest->owner_entry_required_at)->toBeNull();
    Http::assertSentCount(3);
});

test('bounded retries turn an unavailable recent business-day rate into one owner-entry Reminder', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-27',
    ]);
    Http::fake([
        'https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-20/2026-07-27/esp' => Http::response(bcrpPayload([
            ['name' => '27.Jul.26', 'values' => ['n.d.']],
        ])),
    ]);
    $action = app(SeedDailyExchangeRateFromBcrpData::class);

    $action->handle($seedRequest->id);
    $this->travelTo($seedRequest->fresh()->next_attempt_at);
    $action->handle($seedRequest->id);
    $this->travelTo($seedRequest->fresh()->next_attempt_at);
    $action->handle($seedRequest->id);
    $action->handle($seedRequest->id);

    $seedRequest->refresh();
    $reminder = Reminder::query()->sole();

    expect($seedRequest->attempt_count)->toBe(3)
        ->and($seedRequest->next_attempt_at)->toBeNull()
        ->and($seedRequest->owner_entry_required_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($seedRequest->reminder_id)->toBe($reminder->id)
        ->and($reminder->subject)->toBe('Enter the Daily Exchange Rate for 2026-07-27')
        ->and($reminder->scheduled_for->toIso8601String())->toBe(now()->toIso8601String())
        ->and(DailyExchangeRate::query()->count())->toBe(0);

    Http::assertSentCount(3);

    $this->actingAs($seedRequest->owner)
        ->get(route('daily_exchange_rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('seed_requests.0.applicable_on', '2026-07-27')
            ->where('seed_requests.0.state', 'owner_entry_required')
            ->where('seed_requests.0.attempt_count', 3)
            ->where('seed_requests.0.next_attempt_at', null));
});

test('owner entry completes seed work and resolves its Reminder without retaining BCRP attribution', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 18:00:00 UTC'));
    $owner = User::factory()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create();
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-27',
        'attempt_count' => 3,
        'owner_entry_required_at' => now()->subHour(),
        'reminder_id' => $reminder->id,
    ]);

    $this->actingAs($owner)
        ->post(route('daily_exchange_rates.store'), [
            'applicable_on' => '2026-07-27',
            'pen_per_usd' => '3.600001',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('daily_exchange_rates.index'));

    $rate = DailyExchangeRate::query()->sole();
    $seedRequest->refresh();

    expect($rate->owner_managed_at)->not->toBeNull()
        ->and($rate->source)->toBeNull()
        ->and($seedRequest->completed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($seedRequest->owner_entry_required_at)->toBeNull()
        ->and($reminder->fresh()->resolved_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($reminder->lifecycleEvents()->sole()->domain_action)->toBe('daily_exchange_rate.entered');
});

test('owner entry may complete parked retrieval failure work', function () {
    $owner = User::factory()->create();
    $seedRequest = DailyExchangeRateSeedRequest::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-27',
        'attempt_count' => 5,
        'transport_failure_count' => 5,
        'retrieval_failed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->post(route('daily_exchange_rates.store'), [
            'applicable_on' => '2026-07-27',
            'pen_per_usd' => '3.600001',
        ])
        ->assertSessionHasNoErrors();

    $seedRequest->refresh();

    expect($seedRequest->completed_at)->not->toBeNull()
        ->and($seedRequest->retrieval_failed_at)->toBeNull()
        ->and(DailyExchangeRate::query()->sole()->owner_managed_at)->not->toBeNull();
});

test('automatic seeding never overwrites an owner-managed rate', function () {
    $owner = User::factory()->create();
    $rate = DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_700_000,
    ]);
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
    ]);

    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);

    expect($rate->fresh()->pen_per_usd_scaled)->toBe(3_700_000)
        ->and($rate->fresh()->owner_managed_at)->not->toBeNull()
        ->and($seedRequest->fresh()->completed_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('an owner value written while BCRPData is in flight wins the insert race', function () {
    $owner = User::factory()->create();
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
    ]);
    app()->instance(BcrpData::class, new class($owner) implements BcrpData
    {
        public function __construct(private User $owner) {}

        public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation
        {
            DailyExchangeRate::factory()->for($this->owner, 'owner')->create([
                'applicable_on' => $applicableOn,
                'pen_per_usd_scaled' => 3_700_000,
            ]);

            return new BcrpExchangeRateObservation(
                observedOn: $applicableOn,
                retrievedAt: CarbonImmutable::now(),
                value: '3.545',
                sourcePrecision: 3,
            );
        }
    });

    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);

    $rate = DailyExchangeRate::query()->sole();

    expect($rate->pen_per_usd_scaled)->toBe(3_700_000)
        ->and($rate->owner_managed_at)->not->toBeNull()
        ->and($rate->source)->toBeNull()
        ->and($seedRequest->fresh()->completed_at)->not->toBeNull();
});

test('an unexpected BCRP series is rejected without trying another source', function () {
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-24',
    ]);
    $payload = bcrpPayload([
        ['name' => '24.Jul.26', 'values' => ['3.545']],
    ]);
    $payload['config']['series'][0]['name'] = 'Another exchange-rate series';
    Http::fake([
        'https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-17/2026-07-24/esp' => Http::response($payload),
    ]);

    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);
    $seedRequest->refresh();

    expect(DailyExchangeRate::query()->count())->toBe(0)
        ->and($seedRequest->last_error_code)->toBe('bcrp_response_invalid')
        ->and($seedRequest->next_attempt_at)->toBeNull()
        ->and($seedRequest->retrieval_failed_at)->not->toBeNull()
        ->and($seedRequest->owner->integrationIncidents()->sole()->failure_kind)
        ->toBe(IntegrationFailureKind::Schema);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/PD04638PD/'));
});

test('transport failures do not consume the publication-gap retry allowance', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-27',
    ]);
    $bcrpData = new class implements BcrpData
    {
        public int $calls = 0;

        public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation
        {
            $this->calls++;

            if ($this->calls <= 2) {
                throw new ConnectionException('BCRPData is temporarily unavailable.');
            }

            return new BcrpExchangeRateObservation(
                observedOn: CarbonImmutable::parse('2026-07-24', 'America/Lima'),
                retrievedAt: CarbonImmutable::now(),
                value: '3.545',
                sourcePrecision: 3,
            );
        }
    };
    app()->instance(BcrpData::class, $bcrpData);
    $action = app(SeedDailyExchangeRateFromBcrpData::class);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $action->handle($seedRequest->id);

        if ($attempt < 5) {
            $this->travelTo($seedRequest->fresh()->next_attempt_at);
        }
    }

    $seedRequest->refresh();
    $rate = DailyExchangeRate::query()->sole();

    expect($bcrpData->calls)->toBe(5)
        ->and($seedRequest->attempt_count)->toBe(5)
        ->and($seedRequest->missing_observation_count)->toBe(2)
        ->and($seedRequest->completed_at)->not->toBeNull()
        ->and($seedRequest->owner_entry_required_at)->toBeNull()
        ->and($rate->source_observed_on->toDateString())->toBe('2026-07-24');
});

test('transport retry parking after one day remains distinct from owner-entry work', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-27',
    ]);
    app()->instance(BcrpData::class, new class implements BcrpData
    {
        public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation
        {
            throw new ConnectionException('BCRPData is temporarily unavailable.');
        }
    });
    $action = app(SeedDailyExchangeRateFromBcrpData::class);

    $action->handle($seedRequest->id);
    $this->travelTo($seedRequest->owner->integrationIncidents()->sole()->retry_until);
    $action->handle($seedRequest->id);

    $seedRequest->refresh();

    expect($seedRequest->transport_failure_count)->toBe(2)
        ->and($seedRequest->retrieval_failed_at)->not->toBeNull()
        ->and($seedRequest->owner_entry_required_at)->toBeNull()
        ->and($seedRequest->reminder_id)->toBeNull();
});

test('unexpected worker failures park for visible manual replay after the overall attempt cap', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00 UTC'));
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-27',
    ]);
    app()->instance(BcrpData::class, new class implements BcrpData
    {
        public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation
        {
            throw new RuntimeException('Unexpected adapter failure.');
        }
    });
    $action = app(SeedDailyExchangeRateFromBcrpData::class);

    for ($attempt = 1; $attempt <= 8; $attempt++) {
        expect(fn () => $action->handle($seedRequest->id))->toThrow(RuntimeException::class);
        $this->travel(2)->minutes();
    }

    $action->handle($seedRequest->id);
    $seedRequest->refresh();

    expect($seedRequest->attempt_count)->toBe(8)
        ->and($seedRequest->owner_entry_required_at)->toBeNull()
        ->and($seedRequest->retrieval_failed_at)->not->toBeNull()
        ->and($seedRequest->last_error_code)->toBe('retry_exhausted')
        ->and($seedRequest->reminder_id)->toBeNull();

    $this->actingAs($seedRequest->owner)
        ->get(route('daily_exchange_rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('seed_requests.0.state', 'retrieval_failed'));

    Queue::fake();
    $this->post(route('daily_exchange_rates.retry_seed', $seedRequest))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('daily_exchange_rates.index'));

    $seedRequest->refresh();

    expect($seedRequest->attempt_count)->toBe(0)
        ->and($seedRequest->missing_observation_count)->toBe(0)
        ->and($seedRequest->transport_failure_count)->toBe(0)
        ->and($seedRequest->retrieval_failed_at)->toBeNull()
        ->and($seedRequest->queued_at?->toIso8601String())->toBe(now()->toIso8601String());
    Queue::assertPushed(
        SeedDailyExchangeRate::class,
        fn (SeedDailyExchangeRate $job): bool => $job->seedRequestId === $seedRequest->id,
    );
});

test('seed work does not persist a rate when its conversion need ends while BCRPData is in flight', function () {
    $seedRequest = DailyExchangeRateSeedRequest::factory()->required()->create([
        'applicable_on' => '2026-07-24',
    ]);
    app()->instance(BcrpData::class, new class($seedRequest) implements BcrpData
    {
        public function __construct(private DailyExchangeRateSeedRequest $seedRequest) {}

        public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation
        {
            Transaction::query()
                ->where('user_id', $this->seedRequest->user_id)
                ->update(['voided_at' => now()]);

            return new BcrpExchangeRateObservation(
                observedOn: $applicableOn,
                retrievedAt: CarbonImmutable::now(),
                value: '3.545',
                sourcePrecision: 3,
            );
        }
    });

    app(SeedDailyExchangeRateFromBcrpData::class)->handle($seedRequest->id);
    $seedRequest->refresh();

    expect($seedRequest->completed_at)->not->toBeNull()
        ->and($seedRequest->last_error_code)->toBe('no_longer_required')
        ->and(DailyExchangeRate::query()->count())->toBe(0);
});

test('discovery resolves stale owner-entry work when its conversion need ends', function () {
    $reminder = Reminder::factory()->create();
    $seedRequest = DailyExchangeRateSeedRequest::factory()
        ->required()
        ->for($reminder->owner, 'owner')
        ->create([
            'applicable_on' => '2026-07-24',
            'attempt_count' => 3,
            'missing_observation_count' => 3,
            'owner_entry_required_at' => now(),
            'reminder_id' => $reminder->id,
        ]);
    Transaction::query()
        ->where('user_id', $seedRequest->user_id)
        ->update(['voided_at' => now()]);

    app(DiscoverMissingDailyExchangeRates::class)->handle();
    $seedRequest->refresh();
    $resolutionKey = $seedRequest->resolution_idempotency_key;

    expect($seedRequest->completed_at)->not->toBeNull()
        ->and($seedRequest->owner_entry_required_at)->toBeNull()
        ->and($seedRequest->last_error_code)->toBe('no_longer_required')
        ->and($reminder->fresh()->resolved_at)->not->toBeNull()
        ->and($reminder->lifecycleEvents()->sole()->domain_action)->toBe('daily_exchange_rate.no_longer_required');

    Transaction::query()
        ->where('user_id', $seedRequest->user_id)
        ->update(['voided_at' => null]);
    app(DiscoverMissingDailyExchangeRates::class)->handle();
    $seedRequest->refresh();

    expect($seedRequest->completed_at)->toBeNull()
        ->and($seedRequest->reminder_id)->toBeNull()
        ->and($seedRequest->resolution_idempotency_key)->not->toBe($resolutionKey);
});

test('owner edits replace BCRP authority and cannot be overwritten automatically later', function () {
    $owner = User::factory()->create();
    $rate = DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'owner_managed_at' => null,
        'source' => 'bcrp_data',
        'source_series' => 'PD04638PD',
        'source_observed_on' => '2026-07-24',
        'source_retrieved_at' => now(),
        'source_value' => '3.545',
        'source_precision' => 3,
    ]);

    $this->actingAs($owner)
        ->patch(route('daily_exchange_rates.update', $rate), [
            'expected_revision' => 1,
            'pen_per_usd' => '3.600001',
        ])
        ->assertSessionHasNoErrors();

    $rate->refresh();

    expect($rate->pen_per_usd_scaled)->toBe(3_600_001)
        ->and($rate->owner_managed_at)->not->toBeNull()
        ->and($rate->source)->toBeNull()
        ->and($rate->source_series)->toBeNull()
        ->and($rate->source_observed_on)->toBeNull()
        ->and($rate->source_retrieved_at)->toBeNull()
        ->and($rate->source_value)->toBeNull()
        ->and($rate->source_precision)->toBeNull();
});

test('the scheduler discovers and queues each missing Daily Exchange Rate once', function () {
    config()->set('cache.default', 'array');
    Queue::fake();
    Schedule::useCache('array');
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-24',
        'currency' => Currency::Usd,
    ]);

    $this->artisan('schedule:run')->assertSuccessful();
    $this->artisan('schedule:run')->assertSuccessful();

    $seedRequest = DailyExchangeRateSeedRequest::query()->sole();

    expect($seedRequest->queued_at)->not->toBeNull();
    Queue::assertPushed(
        SeedDailyExchangeRate::class,
        fn (SeedDailyExchangeRate $job): bool => $job->seedRequestId === $seedRequest->id,
    );
    Queue::assertPushed(SeedDailyExchangeRate::class, 1);
});

test('saving a Transaction records required seed work immediately', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);

    app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-24'),
        amountMinor: 1_000,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Immediate rate need',
    );

    $seedRequest = DailyExchangeRateSeedRequest::query()->sole();

    expect($seedRequest->applicable_on->toDateString())->toBe('2026-07-24')
        ->and($seedRequest->attempt_count)->toBe(0);
});

/**
 * @param  list<array{name: string, values: list<string>}>  $periods
 * @return array<string, mixed>
 */
function bcrpPayload(array $periods, string $precision = '3'): array
{
    return [
        'config' => [
            'title' => 'Tipo de cambio',
            'series' => [[
                'name' => 'Tipo de cambio - TC Interbancario (S/ por US$) - Venta',
                'dec' => $precision,
            ]],
        ],
        'periods' => $periods,
    ];
}
