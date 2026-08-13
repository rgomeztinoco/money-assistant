<?php

use App\Models\Category;
use App\Models\GmailConnection;
use App\Models\ParserProfile;
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
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    Transaction::factory()->purchase()->usd()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 100,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->purchase()->pen()->provisional([
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 500,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->refund()->pen()->create([
        'occurred_on' => '2026-08-12',
        'amount_minor' => 100,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->purchase()->pen()->create([
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
            ->missing('spending.combined_total')
            ->where('review_queue.outstanding_count', 1));
});

test('the Dashboard summarizes enabled Parser Profiles without drift or security aggregation', function () {
    $owner = User::factory()->create();
    GmailConnection::factory()->create();
    ParserProfile::factory()->create([
        'name' => 'Healthy bank alerts',
    ]);
    ParserProfile::factory()->create([
        'name' => 'Card alerts',
    ]);
    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operating.summary.gmail', 'connected')
            ->where('operating.summary.parser_profiles.healthy_count', 2)
            ->where('operating.summary.parser_profiles.degraded_count', 0)
            ->missing('operating.summary.daily_exchange_rates')
            ->has('operating.exceptions', 0));
});

test('the Dashboard promotes stale Gmail synchronization instead of reporting it healthy', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create();
    GmailConnection::factory()->create([
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
