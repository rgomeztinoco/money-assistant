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

test('Trends explains automatic comparisons and links findings to their evidence', function () {
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

    $unusual = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 4_000,
        'description' => 'Central Market',
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
        ->assertSee('Equivalent days in the previous three months')
        ->assertSee('Six-month context')
        ->assertSee('Food')
        ->assertSee('Central Market')
        ->assertSee('Frequency')
        ->assertSee('Unusual Transaction')
        ->assertSee('No recorded activity')
        ->assertSee('If this matched the recent typical level')
        ->assertNotPresent('input[name="date_from"]')
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->click('[data-test="trend-finding-category-'.$food->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('selected', (string) $unusual->id);

    $page = visit('/trends');

    $page
        ->click('[data-test="trend-finding-category-'.$food->id.'-comparison-0"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('date_from', '2026-07-01')
        ->assertQueryStringHas('date_to', '2026-07-22')
        ->assertQueryStringMissing('selected');

    $page = visit('/trends');

    $page
        ->click('[data-test="trend-finding-merchant-central-market"]')
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
