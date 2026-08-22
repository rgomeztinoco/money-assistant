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
    $usdTransaction = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 100,
        'description' => 'Corner shop',
        'category_id' => $category->id,
    ]);
    $penTransaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->provisional([
        ReviewableTransactionField::Description,
    ])->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 500,
        'description' => 'Neighborhood market',
        'category_id' => $category->id,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-12',
        'amount_minor' => 100,
        'description' => 'Market Refund',
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-31',
        'amount_minor' => 9_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-06-30',
        'amount_minor' => 7_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-05-31',
        'amount_minor' => 6_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-15',
        'amount_minor' => 8_000,
        'description' => 'Voided transfer',
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

test('the Dashboard compares equivalent periods and highlights the largest Category changes by currency', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 4_000,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 2_000,
        'category_id' => $transport->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-03',
        'amount_minor' => 2_000,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 3_500,
        'category_id' => $transport->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 1_000,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-20',
        'amount_minor' => 50_000,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 70_000,
        'category_id' => $food->id,
        'voided_at' => now(),
    ]);
    Transaction::factory()->for($otherOwner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 90_000,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison_period.date_from', '2026-07-01')
            ->where('comparison_period.date_to', '2026-07-18')
            ->where('spending.comparisons.PEN.current_total_minor', '6000')
            ->where('spending.comparisons.PEN.previous_total_minor', '5500')
            ->where('spending.comparisons.PEN.change_minor', '500')
            ->where('spending.comparisons.PEN.percentage_change', '9.09')
            ->where('spending.comparisons.PEN.direction', 'increased')
            ->where('spending.comparisons.USD.current_total_minor', '1000')
            ->where('spending.comparisons.USD.previous_total_minor', '0')
            ->where('spending.comparisons.USD.percentage_change', null)
            ->where('spending.comparisons.USD.direction', 'no_baseline')
            ->where('spending.category_insights.PEN.0.category.id', $food->id)
            ->where('spending.category_insights.PEN.0.category.name', 'Food')
            ->where('spending.category_insights.PEN.0.current_total_minor', '4000')
            ->where('spending.category_insights.PEN.0.previous_total_minor', '2000')
            ->where('spending.category_insights.PEN.0.change_minor', '2000')
            ->where('spending.category_insights.PEN.1.category.id', $transport->id)
            ->where('spending.category_insights.PEN.1.change_minor', '-1500'));
});

test('the Dashboard caps an equivalent previous month at its actual final day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 15:00:00 UTC'));

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison_period.date_from', '2026-02-01')
            ->where('comparison_period.date_to', '2026-02-28'));
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

test('shared Inertia data exposes only the navigation workload outside the Dashboard', function () {
    $owner = User::factory()->create();
    DB::enableQueryLog();

    $response = $this->actingAs($owner)
        ->get(route('transactions.index'));
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    $response
        ->assertInertia(fn (Assert $page) => $page
            ->missing('gmail')
            ->missing('review_queue')
            ->where('navigation.review_queue_count', 0));

    expect($queries)
        ->not->toContain('gmail_connections')
        ->not->toContain('gmail_message_discoveries');
});
