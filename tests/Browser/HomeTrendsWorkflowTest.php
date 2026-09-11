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
        ->assertPresent('[data-test="reporting-controls"]')
        ->assertPresent('[data-test="reporting-currency-all"]')
        ->assertNotPresent('main h1')
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

test('Trends scans and filters the combined change ledger before opening Breakdown', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
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

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
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
        ->assertPresent('[data-test="period-controls"]')
        ->assertPresent('[data-test="reporting-currency-all"]')
        ->assertNotPresent('main h1')
        ->assertDontSee('The change ledger')
        ->assertDontSee('See where Net Spending changed')
        ->assertSee('Monthly context')
        ->assertSee('Net spending')
        ->assertSeeIn('[data-test="trends-summary-pen"]', 'PEN')
        ->assertSeeIn('[data-test="trends-summary-usd"]', 'USD')
        ->assertSee('Compared with')
        ->assertSee('Previous 6 months')
        ->assertSee('Feb–Jul 2026')
        ->assertSee('Food')
        ->assertSee('Central Market')
        ->assertSee('Category and merchant views overlap')
        ->assertSee('Partial month through Aug 22')
        ->assertPresent('[data-test="trends-ledger-all"]')
        ->assertNotPresent('[data-test="trends-ledger-pen"]')
        ->assertNotPresent('[data-test="trends-ledger-usd"]')
        ->assertPresent('[data-test="trend-frequency-pen-category-'.$food->id.'"]')
        ->assertPresent('[data-test="trend-sparkline-pen-category-'.$food->id.'"]')
        ->assertPresent('[data-test="trend-breakdown-pen-category-'.$food->id.'"]')
        ->assertAttribute(
            '[data-test="trend-change-pen-category-'.$transport->id.'"]',
            'data-direction',
            'down',
        )
        ->assertNotPresent('[data-test^="trend-toggle-"]')
        ->assertNotPresent('[data-test^="trend-evidence-"]')
        ->assertNotPresent('input[name="date_from"]')
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        );

    $page
        ->click('[data-test="trends-all-filter-category"]')
        ->assertPathIs('/trends')
        ->assertSee('Aug 1 to Aug 22')
        ->assertPresent('[data-test="trend-breakdown-pen-category-'.$food->id.'"]')
        ->assertNotPresent('[data-test="trend-breakdown-pen-merchant-central-market"]');

    $page
        ->click('[data-test="trends-all-filter-all"]')
        ->click('[data-test="trend-breakdown-pen-category-'.$food->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-22')
        ->assertQueryStringMissing('selected');

    $page = visit('/trends');

    $page
        ->click('[data-test="trend-breakdown-pen-merchant-central-market"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('merchant', 'Central Market')
        ->assertQueryStringMissing('selected');

    $page = visit('/trends?period=month&anchor=2026-07-12');

    $page
        ->click('[data-test="reporting-currency-usd"]')
        ->assertPathIs('/trends')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas('period', 'month')
        ->assertQueryStringHas('anchor', '2026-07-01')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit('/trends');

    $page
        ->resize(1280, 720)
        ->assertScript(<<<'JS'
            (() => {
                const contextBar = document.querySelector(
                    '[data-test="trends-context-bar"]',
                );
                const overview = document.querySelector(
                    '[data-test="trends-overview-column"]',
                );
                const ledger = document.querySelector(
                    '[data-test="trends-ledger-all"]',
                );
                const ledgerScroll = document.querySelector(
                    '[data-test="trends-ledger-scroll"]',
                );
                const chart = document.querySelector(
                    '[data-test="trends-monthly-context"] [data-slot="chart"]',
                );
                const ledgerNote = document.querySelector(
                    '[data-test="trends-ledger-note"]',
                );
                const ledgerHeader = document.querySelector(
                    '[data-test="trends-ledger-header"]',
                );
                const ledgerColumnHeader = document.querySelector(
                    '[data-test="trends-ledger-column-header"]',
                );
                const overviewContent = overview?.querySelector(
                    '[data-slot="card-content"]',
                );
                const netSpendingHeading = document.querySelector(
                    '[data-test="trends-net-spending"] h2',
                );
                const monthlyContextHeading = document.querySelector(
                    '[data-test="trends-monthly-context"] h2',
                );
                const currencySummaries = document.querySelectorAll(
                    '[data-test^="trends-summary-"]',
                );

                if (
                    contextBar === null
                    || overview === null
                    || ledger === null
                    || ledgerScroll === null
                    || chart === null
                    || ledgerNote === null
                    || ledgerHeader === null
                    || ledgerColumnHeader === null
                    || overviewContent === null
                    || netSpendingHeading === null
                    || monthlyContextHeading === null
                    || currencySummaries.length !== 2
                ) {
                    return false;
                }

                ledgerScroll.scrollTop = 120;

                const contextStyle = getComputedStyle(contextBar);
                const overviewBounds = overview.getBoundingClientRect();
                const ledgerBounds = ledger.getBoundingClientRect();
                const noteBounds = ledgerNote.getBoundingClientRect();
                const ledgerStyle = getComputedStyle(ledger);
                const ledgerHeaderStyle = getComputedStyle(ledgerHeader);
                const ledgerColumnHeaderStyle =
                    getComputedStyle(ledgerColumnHeader);
                const overviewContentStyle = getComputedStyle(overviewContent);
                const netSpendingHeadingStyle =
                    getComputedStyle(netSpendingHeading);
                const monthlyContextHeadingStyle =
                    getComputedStyle(monthlyContextHeading);
                const penSummaryBounds =
                    currencySummaries[0].getBoundingClientRect();
                const usdSummaryBounds =
                    currencySummaries[1].getBoundingClientRect();

                return ledger.textContent.includes('S/ 45.00')
                    && ledger.textContent.includes('$ 25.00')
                    && document.querySelectorAll(
                        '[data-test="trends-monthly-context"]',
                    ).length === 1
                    && chart.getAttribute('data-stacked') === 'true'
                    && contextStyle.borderTopWidth === '0px'
                    && contextStyle.borderBottomWidth === '0px'
                    && Math.abs(overviewBounds.top - ledgerBounds.top) < 1
                    && Math.abs(overviewBounds.bottom - ledgerBounds.bottom) < 1
                    && ledgerBounds.bottom <= innerHeight
                    && noteBounds.bottom <= ledgerBounds.bottom
                    && noteBounds.top >= ledgerScroll.getBoundingClientRect().bottom
                    && ledgerHeaderStyle.paddingTop === '16px'
                    && ledgerHeaderStyle.paddingBottom === '16px'
                    && !ledgerHeader.classList.contains('h-12')
                    && ledgerColumnHeaderStyle.fontSize === '14px'
                    && ledgerColumnHeaderStyle.lineHeight === '20px'
                    && ledgerColumnHeaderStyle.fontWeight === '500'
                    && ledgerColumnHeaderStyle.color === ledgerStyle.color
                    && overviewContentStyle.paddingTop === '24px'
                    && overviewContentStyle.paddingRight === '24px'
                    && overviewContentStyle.gap === '24px'
                    && netSpendingHeadingStyle.fontSize
                        === monthlyContextHeadingStyle.fontSize
                    && netSpendingHeadingStyle.lineHeight
                        === monthlyContextHeadingStyle.lineHeight
                    && netSpendingHeadingStyle.fontWeight
                        === monthlyContextHeadingStyle.fontWeight
                    && Math.abs(penSummaryBounds.top - usdSummaryBounds.top) < 1
                    && usdSummaryBounds.left >= penSummaryBounds.right
                    && document.documentElement.scrollHeight
                        <= document.documentElement.clientHeight
                    && getComputedStyle(ledgerScroll).overflowY === 'auto'
                    && ledgerScroll.scrollHeight > ledgerScroll.clientHeight
                    && ledgerScroll.scrollTop > 0;
            })()
            JS)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit('/trends');

    $page
        ->click('[aria-label="Previous month"]')
        ->assertPathIs('/trends')
        ->assertQueryStringMissing('currency')
        ->assertQueryStringHas('period', 'month')
        ->assertQueryStringHas('anchor', '2026-07-01')
        ->assertSee('July 2026');

});

test('Reporting period and currency persist between the main money pages', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 2_500,
    ]);
    $this->actingAs($owner);

    visit('/breakdown?currency=USD&period=month&anchor=2026-07-12')
        ->assertPresent('[data-test="breakdown-filter-bar"]')
        ->assertPresent('[data-test="reporting-currency-usd"]')
        ->click('[data-test="nav-trends"]')
        ->assertPathIs('/trends')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas('period', 'month')
        ->assertQueryStringHas('anchor', '2026-07-01')
        ->assertSee('July 2026')
        ->click('[data-test="nav-home"]')
        ->assertPathIs('/')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas('period', 'month')
        ->assertQueryStringHas('anchor', '2026-07-01')
        ->assertSee('July 2026')
        ->click('[data-test="reporting-currency-all"]')
        ->assertQueryStringMissing('currency')
        ->click('[data-test="nav-breakdown"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringMissing('currency')
        ->assertQueryStringHas('period', 'month')
        ->assertQueryStringHas('anchor', '2026-07-01')
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
        ->assertSee('No activity')
        ->assertSee('Partial month through Aug 22')
        ->assertSee('No USD activity');

    $emptyOwner = User::factory()->create();
    $this->actingAs($emptyOwner);

    visit('/trends')
        ->assertSee('No activity')
        ->assertSee('No recorded activity');

    $steadyOwner = User::factory()->create();
    $food = Category::factory()->for($steadyOwner, 'owner')->create(['name' => 'Food']);

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10'] as $occurredOn) {
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
