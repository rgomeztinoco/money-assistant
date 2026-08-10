<?php

use App\Currency;
use App\IntegrationFailureKind;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\Category;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\GmailConnection;
use App\Models\IntegrationIncident;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the Dashboard shows current-month spending and Review Queue workload', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create();
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-08-04',
        'pen_per_usd_scaled' => 3_500_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 100,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->provisional([
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 500,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-12',
        'amount_minor' => 100,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-31',
        'amount_minor' => 9_000,
        'category_id' => $category->id,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('period.label', 'August 2026')
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-18')
            ->where('spending.totals.USD', '100')
            ->where('spending.totals.PEN', '400')
            ->where('spending.combined_total.currency', Currency::Pen->value)
            ->where('spending.combined_total.amount_minor', '750')
            ->where('review_queue.outstanding_count', 1));
});

test('the Dashboard promotes only actionable integration and parser exceptions', function () {
    $owner = User::factory()->create();
    GmailConnection::factory()->for($owner, 'owner')->create();
    ParserProfile::factory()->for($owner, 'owner')->create([
        'name' => 'Healthy bank alerts',
    ]);
    $degradedProfile = ParserProfile::factory()->for($owner, 'owner')->create([
        'name' => 'Card alerts',
    ]);
    $degradedVersion = ParserProfileVersion::factory()
        ->for($degradedProfile, 'profile')
        ->create();
    SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($degradedVersion, 'profileVersion')
        ->create([
            'transaction_id' => null,
            'processing_outcome' => 'authentication_failed',
        ]);
    DailyExchangeRateSeedRequest::factory()->for($owner, 'owner')->create();
    DailyExchangeRateSeedRequest::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-08-07',
        'owner_entry_required_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operating.summary.gmail', 'connected')
            ->where('operating.summary.parser_profiles.healthy_count', 1)
            ->where('operating.summary.parser_profiles.degraded_count', 1)
            ->where('operating.summary.daily_exchange_rates.attention_count', 1)
            ->has('operating.exceptions', 2)
            ->where('operating.exceptions.0.type', 'parser_security')
            ->where('operating.exceptions.0.profile_id', $degradedProfile->id)
            ->where('operating.exceptions.0.count', 1)
            ->where('operating.exceptions.1.type', 'missing_exchange_rate')
            ->where('operating.exceptions.1.applicable_on', '2026-08-07'));
});

test('the Dashboard promotes stale Gmail synchronization instead of reporting it healthy', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create();
    GmailConnection::factory()->for($owner, 'owner')->create([
        'last_successful_sync_at' => now()->subMinutes(6),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operating.summary.gmail', 'stale')
            ->has('operating.exceptions', 1)
            ->where('operating.exceptions.0.type', 'gmail_connection')
            ->where('operating.exceptions.0.state', 'stale'));
});

test('the Dashboard deep-links actionable integration incidents and exposes recovery', function () {
    $owner = User::factory()->create();
    GmailConnection::factory()->for($owner, 'owner')->create([
        'last_successful_sync_at' => now(),
    ]);
    $incident = IntegrationIncident::factory()->for($owner, 'owner')->create([
        'integration' => IntegrationService::Gmail,
        'work_type' => IntegrationWorkType::GmailSynchronization,
        'work_id' => 'gmail-connection',
        'source_identity' => 'gmail:synchronization:gmail-connection',
        'failure_kind' => IntegrationFailureKind::Transient,
        'visible_at' => now(),
        'parked_at' => now(),
        'next_attempt_at' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('operating.exceptions', 1)
            ->where('operating.exceptions.0.type', 'integration_incident')
            ->where('operating.exceptions.0.incident_id', $incident->id)
            ->where('operating.exceptions.0.integration', 'gmail')
            ->where('operating.exceptions.0.state', 'parked')
            ->where('operating.exceptions.0.replayable', true)
            ->where(
                'operating.exceptions.0.affected_url',
                route('connections.edit', [
                    'integration' => 'gmail',
                ]).'#gmail',
            ));

    $this->post(route('integration_incidents.acknowledgement.store', $incident))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($incident->fresh()->acknowledged_at)->not->toBeNull();
});
