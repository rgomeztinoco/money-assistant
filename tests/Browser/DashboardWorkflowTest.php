<?php

use App\Models\Category;
use App\Models\GmailConnection;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the Dashboard directs attention into filtered owner workflows', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 1_000,
        'merchant_description' => 'Coffee shop',
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->provisional([
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 2_500,
        'merchant_description' => 'Neighborhood market',
        'category_id' => $category->id,
    ]);
    GmailConnection::factory()->for($owner, 'owner')->create([
        'gmail_account_identity' => 'owner@example.com',
    ]);
    $this->actingAs($owner);

    $page = visit('/dashboard');

    $page
        ->assertSee('PEN current-period total')
        ->assertSee('USD current-period total')
        ->assertSee('$ 10.00')
        ->assertSee('S/ 25.00')
        ->assertDontSee('Combined spending')
        ->assertSee('Recent Transactions')
        ->assertSee('Coffee shop')
        ->assertSee('Neighborhood market')
        ->assertSee('Review Queue')
        ->assertSee('Gmail')
        ->assertSee('Connected')
        ->assertSee('owner@example.com')
        ->assertDontSee('Operating attention')
        ->assertDontSee('Parser Profile health')
        ->assertDontSee('Reminder')
        ->assertDontSee('incident')
        ->click('[data-test="dashboard-spending-usd"]')
        ->assertPathIs('/transactions')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas(
            'date_from',
            now()->startOfMonth()->toDateString(),
        )
        ->assertQueryStringHas('date_to', now()->toDateString())
        ->script('window.location.assign("/dashboard")');

    $page
        ->click('[data-test="dashboard-review-link"]')
        ->assertPathIs('/review-queue')
        ->script('window.location.assign("/dashboard")');

    $page
        ->click('[data-test="dashboard-gmail-link"]')
        ->assertPathIs('/settings/connections')
        ->script('window.location.assign("/dashboard")');

    $page
        ->click('[data-test="nav-reports"]')
        ->assertPathIs('/reports/PEN')
        ->assertSee('PEN spending report')
        ->assertDontSee('Spending Baseline')
        ->assertDontSee('Category Targets')
        ->click('[data-test="report-switch-usd"]')
        ->assertPathIs('/reports/USD')
        ->assertSee('USD spending report')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('navigation exposes only retained personal-finance destinations', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/dashboard');

    $page
        ->assertSee('Dashboard')
        ->assertSee('Transactions')
        ->assertSee('Review Queue')
        ->assertSee('Reports')
        ->assertSee('Categories')
        ->assertSee('Merchant Rules')
        ->assertSee('Parser Profiles')
        ->assertDontSee('OpenClaw')
        ->assertDontSee('Exchange Rates')
        ->assertDontSee('Reminders')
        ->assertDontSee('Incidents')
        ->assertDontSee('Monitoring')
        ->click('[data-test="sidebar-menu-button"]')
        ->assertSee('Settings')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
