<?php

use App\Currency;
use App\Models\Category;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Tests\Support\ConfigureOpenClaw;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the Dashboard directs attention into filtered owner workflows', function () {
    ConfigureOpenClaw::asConfigured();
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create();
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => now()->toDateString(),
        'pen_per_usd_scaled' => 3_500_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 1_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->provisional([
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 2_500,
        'category_id' => $category->id,
    ]);
    $this->actingAs($owner);

    $page = visit('/dashboard');

    $page
        ->assertSee('Current spending')
        ->assertSee('$ 10.00')
        ->assertSee('S/ 25.00')
        ->assertSee('S/ 60.00')
        ->assertSee('Review Queue')
        ->assertSee('OpenClaw')
        ->assertSee('Configured')
        ->assertScript(
            'document.querySelector(\'[data-test="openclaw-launcher"]\')?.getAttribute(\'href\') === \'https://t.me/money_assistant\'',
        )
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
        ->click('[data-test="dashboard-spending-combined"]')
        ->assertPathIs('/transactions')
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
        ->click('[data-test="nav-insights"]')
        ->assertPathIs('/insights')
        ->assertSee('Spending Insights')
        ->assertSee('Category Targets')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('missing-rate Dashboard cards retain their reporting context and affected work', function () {
    ConfigureOpenClaw::asConfigured();
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => now()->toDateString(),
        'category_id' => $category->id,
    ]);
    DailyExchangeRateSeedRequest::factory()->for($owner, 'owner')->create([
        'applicable_on' => now()->toDateString(),
        'owner_entry_required_at' => now(),
    ]);
    $this->actingAs($owner);

    $page = visit('/dashboard');

    $page
        ->click('[data-test="dashboard-spending-combined"]')
        ->assertPathIs('/daily-exchange-rates')
        ->assertQueryStringHas('date', now()->toDateString())
        ->assertQueryStringHas(
            'date_from',
            now()->startOfMonth()->toDateString(),
        )
        ->assertQueryStringHas('date_to', now()->toDateString())
        ->assertScript(
            'window.location.hash === "#rate-request-'.now()->toDateString().'"',
        )
        ->script('window.location.assign("/dashboard")');

    $page
        ->click('[data-test="dashboard-exception-missing_exchange_rate"]')
        ->assertPathIs('/daily-exchange-rates')
        ->assertQueryStringHas('date', now()->toDateString())
        ->assertQueryStringHas('status', 'attention')
        ->assertScript(
            'window.location.hash === "#rate-request-'.now()->toDateString().'"',
        )
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('an unavailable OpenClaw launcher leads to its connection settings', function () {
    config(['services.openclaw.launcher_url' => null]);
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/dashboard');

    $page
        ->assertSee('OpenClaw')
        ->assertSee('Unavailable')
        ->click('[data-test="openclaw-launcher"]')
        ->assertPathIs('/settings/connections')
        ->assertQueryStringHas('integration', 'openclaw')
        ->assertSee('OpenClaw setup required')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
