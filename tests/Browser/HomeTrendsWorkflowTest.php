<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
});

test('Home keeps the Pulse focused and every claim drills into Breakdown', function () {
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
        'occurred_on' => '2026-07-10',
        'amount_minor' => 1_000,
        'description' => 'Previous USD purchase',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 2_500,
        'description' => 'Current USD purchase',
    ]);
    $this->actingAs($owner);

    $page = visit('/');

    $page
        ->assertTitle('Home - Money Assistant')
        ->assertSee('Home')
        ->assertPresent('header [data-test="period-controls"]')
        ->assertNotPresent('header [data-test="reporting-currency-all"]')
        ->assertPresent(
            'main [data-test="home-context-bar"] [data-test="reporting-currency-all"]',
        )
        ->assertPresent('[data-test="home-overview-card"]')
        ->assertPresent('[data-test="home-signals-card"]')
        ->assertPresent(
            '[data-test="home-signals-card"] [data-test="home-coverage"]',
        )
        ->assertNotPresent(
            '[data-test="home-context-bar"] [data-test="home-coverage"]',
        )
        ->assertNotPresent('main h1')
        ->assertSee('Net Spending')
        ->assertSee('Income')
        ->assertSee('Moved to Savings')
        ->assertSee('Behind the change')
        ->assertPresent('[data-test="home-spending-chart"]')
        ->assertPresent('[data-test="home-today-marker"]')
        ->assertAttribute(
            '[data-test="home-today-marker"]',
            'aria-label',
            'Today, 22 Aug. Observed data ends here.',
        )
        ->assertAttribute(
            '[data-test="home-spending-chart"]',
            'aria-label',
            'Cumulative Net Spending in August 2026 compared with 1 Jul – 22 Jul 2026 for PEN and USD. Observed through 22 Aug; future dates have no values.',
        )
        ->assertPresent('[data-test="home-pen-signal-0"]')
        ->assertPresent('[data-test="home-signal-currency-pen"]')
        ->assertPresent('[data-test="home-signal-currency-usd"]')
        ->assertSee('Food')
        ->assertSee('1 Transaction needs review')
        ->assertSeeIn('[data-test="home-overview-card"]', 'S/ 45.00')
        ->assertSeeIn('[data-test="home-overview-card"]', '$ 25.00')
        ->assertSeeIn('[data-test="home-spending-chart"]', 'PEN · Current')
        ->assertSeeIn('[data-test="home-spending-chart"]', 'PEN · Previous')
        ->assertSeeIn('[data-test="home-spending-chart"]', 'USD · Current')
        ->assertSeeIn('[data-test="home-spending-chart"]', 'USD · Previous')
        ->assertSeeIn('[data-test="home-spending-chart"]', 'Today')
        ->click('[data-test="home-signal-currency-usd"]')
        ->assertPresent('[data-test="home-usd-signal-0"]')
        ->assertSeeIn(
            '[data-test="home-usd-signal-evidence"]',
            'Current USD purchase',
        )
        ->assertSeeIn(
            '[data-test="home-usd-signal-evidence"]',
            'Previous USD purchase',
        )
        ->assertScript(<<<'JS'
            (() => {
                const overview = document.querySelector('[data-test="home-overview-card"]');
                const signals = document.querySelector('[data-test="home-signals-card"]');
                const chart = document.querySelector('[data-test="home-spending-chart"]');
                const coverage = document.querySelector('[data-test="home-coverage-panel"]');
                const usdList = document.querySelector('[data-test="home-usd-signal-list"]');
                const usdEvidence = document.querySelector('[data-test="home-usd-signal-evidence"]');

                if (
                    overview === null || signals === null
                    || chart === null || coverage === null
                    || usdList === null || usdEvidence === null
                ) {
                    return false;
                }

                const overviewBounds = overview.getBoundingClientRect();
                const signalsBounds = signals.getBoundingClientRect();
                const chartBounds = chart.getBoundingClientRect();
                const coverageBounds = coverage.getBoundingClientRect();
                const usdListBounds = usdList.getBoundingClientRect();
                const usdEvidenceBounds = usdEvidence.getBoundingClientRect();

                return Math.abs(overviewBounds.top - signalsBounds.top) < 2
                    && signalsBounds.left > overviewBounds.right
                    && usdEvidenceBounds.top > usdListBounds.bottom
                    && overviewBounds.bottom - chartBounds.bottom < 40
                    && signalsBounds.bottom - coverageBounds.bottom < 2
                    && signalsBounds.right <= document.documentElement.clientWidth;
            })()
            JS)
        ->assertScript(<<<'JS'
            (() => {
                const chart = document.querySelector('[data-test="home-spending-chart"]');
                const marker = chart?.querySelector('[data-test="home-today-marker"]');
                const paths = chart?.querySelectorAll('path.recharts-line-curve');

                if (chart === null || marker === null || paths === undefined || paths.length !== 4) {
                    return false;
                }

                const markerX = Number(marker.getAttribute('x1'));
                const chartRight = chart.getBoundingClientRect().right;
                const markerRight = marker.getBoundingClientRect().right;
                const pathEnds = Array.from(paths).map((path) => {
                    const line = path;
                    return line.getPointAtLength(line.getTotalLength()).x;
                });

                return Number.isFinite(markerX)
                    && markerRight < chartRight - 20
                    && pathEnds.every((x) => Math.abs(x - markerX) < 2);
            })()
            JS)
        ->assertDontSee('Savings and income stay visible')
        ->assertDontSee('Your Transactions are caught up')
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
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-31');

    $page = visit('/');

    $page
        ->click('[data-test="home-net-spending"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('focus', 'net_spending')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-31');

    $page = visit('/');

    $page
        ->click('[data-test="home-pen-material-change"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id);

    $page = visit('/');

    $page
        ->click('[data-test="home-pen-material-comparison"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('date_from', '2026-07-01')
        ->assertQueryStringHas('date_to', '2026-07-22');

    $page = visit('/');

    $page
        ->click('[data-test="home-pen-input-request"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('attention', '1');

    $page = visit('/');

    $page
        ->click('[data-test="home-usd-net-spending"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'USD')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit('/');

    $page
        ->click('[data-test="home-signal-currency-usd"]')
        ->click('[data-test="home-usd-material-change"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-31');
});

test('Home explains the spending direction with selectable Transaction evidence', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);
    $housing = Category::factory()->for($owner, 'owner')->create(['name' => 'Housing']);

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-08',
        'amount_minor' => 6_000,
        'description' => 'Previous Neighborhood Market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-09',
        'amount_minor' => 100,
        'description' => 'Previous uncategorized purchase',
        'category_id' => null,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 1_000,
        'description' => 'Current Neighborhood Market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-09',
        'amount_minor' => 3_200,
        'description' => 'City Bus',
        'category_id' => $transport->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-09',
        'amount_minor' => 2_800,
        'description' => 'Monthly rent',
        'category_id' => $housing->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 100,
        'description' => 'Needs a category',
        'category_id' => null,
    ]);

    $this->actingAs($owner);

    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertSee('Transport is driving the change this month.')
        ->assertSee('Net spending')
        ->assertSee('Income')
        ->assertSee('Moved to savings')
        ->assertSeeIn('[data-test="home-pen-signal-evidence"]', 'Previous Neighborhood Market')
        ->assertSeeIn('[data-test="home-pen-signal-evidence"]', '8 Jul')
        ->click('[data-test="home-pen-signal-1"]')
        ->assertSeeIn('[data-test="home-pen-signal-evidence"]', 'City Bus')
        ->resize(390, 844)
        ->click('[data-test="home-pen-input-request"]')
        ->assertPathIs('/breakdown')
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
        ->assertSee('Feb – Jul 2026')
        ->assertSee('August 2026')
        ->assertSee('Food')
        ->assertSee('Central Market')
        ->assertSee('Category and merchant views overlap')
        ->assertSee('Partial month through 22 Aug')
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
        ->assertSee('August 2026')
        ->assertPresent('[data-test="trend-breakdown-pen-category-'.$food->id.'"]')
        ->assertNotPresent('[data-test="trend-breakdown-pen-merchant-central-market"]');

    $page
        ->click('[data-test="trends-all-filter-all"]')
        ->click('[data-test="trend-breakdown-pen-category-'.$food->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-31')
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

test('Reporting labels use the shared period and range grammar', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    visit(route('trends.index', [
        'period' => 'custom',
        'date_from' => '2026-07-01',
        'date_to' => '2026-07-22',
    ]))
        ->assertSee('1 Jul – 22 Jul 2026');

    visit(route('trends.index', [
        'period' => 'custom',
        'date_from' => '2026-07-28',
        'date_to' => '2026-08-03',
    ]))
        ->assertSee('28 Jul – 3 Aug 2026');

    visit(route('trends.index', [
        'period' => 'custom',
        'date_from' => '2025-12-28',
        'date_to' => '2026-01-03',
    ]))
        ->assertSee('28 Dec 2025 – 3 Jan 2026');

    visit(route('trends.index', [
        'period' => 'quarter',
        'anchor' => '2026-08-22',
    ]))
        ->assertSee('Q3 2026');

    visit(route('trends.index', [
        'period' => 'year',
        'anchor' => '2026-08-22',
    ]))
        ->assertSee('2026')
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
        ->assertSee('Partial month through 22 Aug')
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
