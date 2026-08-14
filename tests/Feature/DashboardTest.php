<?php

use App\Models\Category;
use App\Models\GmailConnection;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

test('the Dashboard shows current-period totals, Review Queue workload, recent Transactions, and Gmail status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $usdTransaction = Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 100,
        'merchant_description' => 'Corner shop',
        'category_id' => $category->id,
    ]);
    $penTransaction = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->provisional([
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 500,
        'merchant_description' => 'Neighborhood market',
        'category_id' => $category->id,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-12',
        'amount_minor' => 100,
        'merchant_description' => 'Market Refund',
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-31',
        'amount_minor' => 9_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-06-30',
        'amount_minor' => 7_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-05-31',
        'amount_minor' => 6_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-15',
        'amount_minor' => 8_000,
        'merchant_description' => 'Voided transfer',
        'category_id' => $category->id,
        'voided_at' => now(),
    ]);
    GmailConnection::factory()->for($owner, 'owner')->create([
        'gmail_account_identity' => 'owner@example.com',
        'last_successful_sync_at' => now()->subMinute(),
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
            ->where('review_queue.outstanding_count', 1)
            ->has('recent_transactions', 5)
            ->where('recent_transactions.0.id', $refund->id)
            ->where('recent_transactions.1.id', $penTransaction->id)
            ->where('recent_transactions.2.id', $usdTransaction->id)
            ->where('gmail.state', 'connected')
            ->where('gmail.account_identity', 'owner@example.com')
            ->where('gmail.last_successful_sync_at', fn (mixed $timestamp) => is_string($timestamp))
            ->missing('gmail.latest_failure')
            ->missing('gmail.scope'));
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
            ->where('gmail.state', 'stale'));
});

test('shared Inertia data does not query or expose Dashboard integration status and workload', function () {
    $owner = User::factory()->create();
    DB::enableQueryLog();

    $response = $this->actingAs($owner)
        ->get(route('transactions.index'));
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    $response
        ->assertInertia(fn (Assert $page) => $page
            ->missing('gmail')
            ->missing('review_queue'));

    expect($queries)
        ->not->toContain('gmail_connections')
        ->not->toContain('gmail_message_discoveries');
});
