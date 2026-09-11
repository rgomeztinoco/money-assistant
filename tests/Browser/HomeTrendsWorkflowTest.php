<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
});

test('Home keeps the weekly briefing focused and every claim drills into Breakdown', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);

    foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 1_000,
            'description' => 'Central Market',
            'category_id' => $food->id,
        ]);
    }

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 4_000,
        'description' => 'Central Market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-09',
        'amount_minor' => 500,
        'description' => 'Needs a Category',
        'category_id' => null,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 2_500,
    ]);
    $this->actingAs($owner);

    $page = visit('/');

    $page
        ->assertTitle('Home - Money Assistant')
        ->assertSee('Home')
        ->assertSee('Coverage')
        ->assertSee('Net Spending')
        ->assertSee('Income')
        ->assertSee('Moved to Savings')
        ->assertSee('One material change')
        ->assertSee('Food')
        ->assertSee('Needs your input')
        ->assertSee('USD')
        ->assertDontSee('Recent Transactions')
        ->assertDontSee('Review Queue')
        ->assertDontSeeIn('main', 'Parser Profiles')
        ->assertSeeIn('[data-test="nav-home"]', 'Home')
        ->assertSeeIn('[data-test="nav-breakdown"]', 'Breakdown')
        ->assertSeeIn('[data-test="nav-trends"]', 'Trends')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Reports')
        ->resize(390, 844)
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->click('[data-test="home-coverage"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('date_from', '2026-08-08')
        ->assertQueryStringHas('date_to', '2026-08-09');

    $page = visit('/');

    $page
        ->click('[data-test="home-net-spending"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('focus', 'net_spending')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-22');

    $page = visit('/');

    $page
        ->click('[data-test="home-material-change"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id);

    $page = visit('/');

    $page
        ->click('[data-test="home-material-comparison-0"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('date_from', '2026-07-01')
        ->assertQueryStringHas('date_to', '2026-07-22');

    $page = visit('/');

    $page
        ->click('[data-test="home-input-request"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('attention', '1');

    $page = visit('/');

    $page
        ->click('[data-test="home-usd-breakdown"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'USD')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Trends scans, filters, and expands the change ledger before opening its evidence', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 1_000,
            'description' => 'Central Market',
            'category_id' => $food->id,
        ]);
        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 2_000,
            'description' => 'City Bus',
            'category_id' => $transport->id,
        ]);
    }

    $unusual = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 4_000,
        'description' => 'Central Market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-09',
        'amount_minor' => 1_000,
        'description' => 'City Bus',
        'category_id' => $transport->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 300,
        'description' => 'Café',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-11',
        'amount_minor' => 200,
        'description' => 'Cafè',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 2_500,
    ]);
    $this->actingAs($owner);

    $page = visit('/trends')->resize(390, 844);

    $page
        ->assertTitle('Trends - Money Assistant')
        ->assertSee('The change ledger')
        ->assertSee('Calendar-month context')
        ->assertSee('Food')
        ->assertSee('Central Market')
        ->assertSee('Category and merchant views overlap')
        ->assertSee('No recorded activity')
        ->assertSee('Current partial month through Aug 22')
        ->assertAttribute(
            '[data-test="trend-change-category-'.$transport->id.'"]',
            'data-direction',
            'down',
        )
        ->assertNotPresent('[data-test="trend-evidence-category-'.$food->id.'"]')
        ->assertNotPresent('input[name="date_from"]')
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->click('[data-test="trend-toggle-category-'.$food->id.'"]')
        ->assertPresent('[data-test="trend-evidence-category-'.$food->id.'"]')
        ->assertSee('Transaction frequency')
        ->assertSee('Unusual Transaction')
        ->keys('[data-test="trend-toggle-merchant-central-market"]', 'Enter')
        ->assertPresent('[data-test="trend-evidence-category-'.$food->id.'"]')
        ->assertPresent('[data-test="trend-evidence-merchant-central-market"]')
        ->keys('[data-test="trend-toggle-category-'.$food->id.'"]', 'Enter')
        ->assertNotPresent('[data-test="trend-evidence-category-'.$food->id.'"]')
        ->assertPresent('[data-test="trend-evidence-merchant-central-market"]')
        ->click('[data-test="trend-toggle-merchant-café"]')
        ->keys('[data-test="trend-toggle-merchant-cafè"]', 'Enter')
        ->assertPresent('[data-test="trend-evidence-merchant-café"]')
        ->assertPresent('[data-test="trend-evidence-merchant-cafè"]')
        ->keys('[data-test="trend-toggle-merchant-café"]', 'Enter')
        ->assertNotPresent('[data-test="trend-evidence-merchant-café"]')
        ->assertPresent('[data-test="trend-evidence-merchant-cafè"]')
        ->click('[data-test="trends-filter-category"]')
        ->assertPathIs('/trends')
        ->assertSee('Aug 1 – Aug 22, 2026')
        ->assertPresent('[data-test="trend-toggle-category-'.$food->id.'"]')
        ->assertNotPresent('[data-test="trend-toggle-merchant-central-market"]')
        ->click('[data-test="trends-filter-all"]')
        ->click('[data-test="trend-toggle-category-'.$food->id.'"]')
        ->click('[data-test="trend-breakdown-category-'.$food->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('selected', (string) $unusual->id);

    $page = visit('/trends');

    $page
        ->click('[data-test="trend-toggle-category-'.$food->id.'"]')
        ->click('[data-test="trend-finding-category-'.$food->id.'-comparison-0"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('date_from', '2026-07-01')
        ->assertQueryStringHas('date_to', '2026-07-22')
        ->assertQueryStringMissing('selected');

    $page = visit('/trends');

    $page
        ->click('[data-test="trend-toggle-merchant-central-market"]')
        ->click('[data-test="trend-breakdown-merchant-central-market"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('merchant', 'Central Market');

    $page = visit('/trends');

    $page
        ->click('[data-test="trends-switch-usd"]')
        ->assertPathIs('/trends')
        ->assertQueryStringHas('currency', 'USD')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Trends distinguishes empty activity, missing context, and no material findings', function () {
    $historicalOwner = User::factory()->create();
    Transaction::factory()->for($historicalOwner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 1_000,
    ]);
    $this->actingAs($historicalOwner);

    visit('/trends')
        ->assertSee('No PEN activity this month to date')
        ->assertSee('Current partial month through Aug 22')
        ->assertSee('No recorded activity');

    $emptyOwner = User::factory()->create();
    $this->actingAs($emptyOwner);

    visit('/trends')
        ->assertSee('No PEN activity this month to date')
        ->assertSee('No six-month activity recorded');

    $steadyOwner = User::factory()->create();
    $food = Category::factory()->for($steadyOwner, 'owner')->create(['name' => 'Food']);

    foreach (['2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10'] as $occurredOn) {
        Transaction::factory()->for($steadyOwner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 1_000,
            'description' => 'Central Market',
            'category_id' => $food->id,
        ]);
    }
    $this->actingAs($steadyOwner);

    visit('/trends')
        ->assertSee('No material findings')
        ->assertSee('No material Category or merchant change')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
