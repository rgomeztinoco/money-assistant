<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('Breakdown searches, filters, and pages loaded Transactions without data requests', function () {
    $owner = User::factory()->create();
    $date = now()->toDateString();
    Transaction::factory()
        ->count(999)
        ->for($owner, 'owner')
        ->spending()
        ->pen()
        ->sequence(fn (Sequence $sequence) => [
            'occurred_on' => now()->startOfYear()->addDays($sequence->index % 250)->toDateString(),
            'description' => 'Other merchant',
            'amount_minor' => 500,
        ])
        ->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => $date,
        'description' => 'Starbucks refund',
        'amount_minor' => 1250,
    ]);
    $outsidePeriod = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => now()->subYear()->toDateString(),
        'description' => 'Outside this year',
        'amount_minor' => 1250,
    ]);
    $this->actingAs($owner);

    $page = visit("/breakdown?period=year&anchor={$date}&currency=PEN");

    $page->script(<<<'JS'
        window.__breakdownRequests = performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/breakdown')).length;
        window.__breakdownSummary = document.querySelector('[data-test="breakdown-summary"]')?.textContent;
        JS);

    $page
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1000 matching Transactions')
        ->press('Next')
        ->assertSee('Page 2 of 20');

    $page->script('document.querySelector(\'[data-test^="breakdown-transaction-"]\').click()');

    $page
        ->assertSee('Transaction details')
        ->click('[data-slot="dialog-content"] > button')
        ->assertQueryStringMissing('selected')
        ->assertSee('Page 2 of 20');

    $page->script(<<<'JS'
        window.__breakdownRequests = performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/breakdown')).length;
        JS);

    $page
        ->fill('#transaction-search', 'STAR')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1 matching Transaction')
        ->assertSee('Starbucks refund')
        ->assertSeeIn('[data-test="transaction-row-id-'.$refund->id.'"]', '#'.$refund->id)
        ->assertNotPresent('label[for="transaction-search"]')
        ->press('Filters')
        ->fill('#filter-amount-min', '20.00')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1 matching Transaction')
        ->press('Apply')
        ->assertSee('No matching Transactions')
        ->press('Filters')
        ->fill('#filter-amount-min', '12.50')
        ->fill('#filter-amount-max', '12.50')
        ->click('#filter-kind-spending')
        ->click('#filter-kind-income')
        ->click('#filter-kind-transfer')
        ->press('Apply')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1 matching Transaction')
        ->assertSee('Starbucks refund')
        ->assertScript('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/breakdown")).length === window.__breakdownRequests')
        ->assertScript('document.querySelector(\'[data-test="breakdown-summary"]\')?.textContent === window.__breakdownSummary')
        ->click('[data-test="breakdown-transaction-'.$refund->id.'"]')
        ->assertSee('Transaction details')
        ->press('Close')
        ->assertQueryStringMissing('selected')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1 matching Transaction')
        ->assertScript('document.querySelector("#transaction-search")?.value === "STAR"');

    $page->script('window.__breakdownRequests = performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/breakdown")).length');

    $page
        ->fill('#transaction-search', (string) $refund->id)
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1 matching Transaction')
        ->assertSee('Starbucks refund')
        ->fill('#transaction-search', (string) $outsidePeriod->id)
        ->assertSee('No matching Transactions')
        ->assertScript('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/breakdown")).length === window.__breakdownRequests')
        ->assertNoJavaScriptErrors();
});

test('Category and day charts drill into the same supporting detail', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Food',
    ]);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    $transport = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Transport',
    ]);
    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 2_500,
        'description' => 'Neighborhood market',
        'category_id' => $dining->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $yesterday,
        'amount_minor' => 1_500,
        'description' => 'Corner cafe',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 900,
        'description' => 'Bus pass',
        'category_id' => $transport->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$yesterday}&date_to={$today}")
        ->inDarkMode();

    $page
        ->assertSee('Summary')
        ->assertSee('Spending over time')
        ->assertSee('Net spending')
        ->assertSee('Recorded activity, not statement verified')
        ->assertScript(<<<'JS'
            (() => {
                const breadcrumbs = document.querySelector('[data-slot="breadcrumb"]');
                const periodControls = document.querySelector('[data-test="period-controls"]');

                if (breadcrumbs === null || periodControls === null) {
                    return false;
                }

                const breadcrumbBounds = breadcrumbs.getBoundingClientRect();
                const periodBounds = periodControls.getBoundingClientRect();

                return Math.abs(
                    breadcrumbBounds.top + breadcrumbBounds.height / 2
                    - (periodBounds.top + periodBounds.height / 2),
                ) < 2;
            })()
            JS)
        ->hover('[data-test="breakdown-day-'.$today.'"]')
        ->assertPresent('[data-test="daily-chart-tooltip"]')
        ->assertScript(<<<'JS'
            (() => {
                const tooltip = document.querySelector('[data-test="daily-chart-tooltip"]');

                if (tooltip === null || !document.documentElement.classList.contains('dark')) {
                    return false;
                }

                const style = getComputedStyle(tooltip);

                return style.backgroundColor !== 'rgba(0, 0, 0, 0)'
                    && style.color !== style.backgroundColor
                    && style.boxShadow !== 'none';
            })()
            JS)
        ->assertSee(now()->subDay()->format('j M'))
        ->assertSee(now()->format('j M'))
        ->click('[aria-label="Choose a custom date range"]')
        ->assertSee('Apply range')
        ->press('Apply range')
        ->assertQueryStringHas('period', 'custom')
        ->click('[data-test="breakdown-tab-categories"]')
        ->assertSee('Where the money went')
        ->assertScript(<<<JS
            (() => {
                const category = document.querySelector(
                    '[data-test="breakdown-category-{$food->id}"]',
                );
                const filterBar = document.querySelector(
                    '[data-test="breakdown-filter-bar"]',
                );
                const track = document.querySelector(
                    '[data-test="breakdown-category-bar-{$food->id}-PEN"]',
                );
                const bar = track?.firstElementChild;

                if (
                    category === null
                    || filterBar === null
                    || track === null
                    || bar === null
                ) {
                    return false;
                }

                const ratio = bar.getBoundingClientRect().width
                    / track.getBoundingClientRect().width;

                return Math.abs(ratio - (4000 / 4900)) < 0.01
                    && !category.textContent.includes('%')
                    && getComputedStyle(filterBar).borderBottomWidth === '0px';
            })()
            JS)
        ->click('[data-test="breakdown-category-'.$food->id.'"]')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertSee('Neighborhood market')
        ->assertSee('Corner cafe')
        ->assertDontSee('Bus pass')
        ->click('[data-test="breakdown-day-'.$today.'"]')
        ->assertQueryStringHas('day', $today)
        ->assertSee('Neighborhood market')
        ->assertDontSee('Corner cafe')
        ->assertDontSee('Bus pass')
        ->assertSee('S/ 25.00')
        ->click('[aria-label="Remove category filter: Food"]')
        ->assertQueryStringMissing('category')
        ->assertQueryStringHas('day', $today)
        ->assertSee('Bus pass')
        ->click('[data-test="breakdown-tab-categories"]')
        ->click('[data-test="breakdown-category-'.$food->id.'"]')
        ->assertQueryStringHas('category', (string) $food->id)
        ->click('[data-test="breakdown-tab-merchants"]')
        ->assertNotPresent('[aria-label="Search every merchant"]')
        ->click('[data-test="breakdown-merchant-Neighborhood market"]')
        ->assertQueryStringHas('merchant', 'Neighborhood market')
        ->assertScript(<<<'JS'
            (() => {
                const chart = document.querySelector('[data-slot="chart"]');
                const chartSection = chart?.closest('section');
                const overviewCard = document.querySelector(
                    '[data-test="breakdown-overview-card"]',
                );
                const transactionsCard = document.querySelector(
                    '[data-test="breakdown-transactions-card"]',
                );
                const transactionsScroll = document.querySelector(
                    '[data-test="breakdown-transactions-scroll"]',
                );
                const transactionsHeader = document.querySelector(
                    '[data-test="breakdown-transactions-header"]',
                );
                const overviewScroll = document.querySelector(
                    '[data-test="breakdown-merchants-scroll"]',
                );

                if (
                    chart === null
                    || chartSection === null
                    || chartSection === undefined
                    || overviewCard === null
                    || transactionsCard === null
                    || transactionsScroll === null
                    || transactionsHeader === null
                    || overviewScroll === null
                ) {
                    return false;
                }

                const overviewBounds = overviewCard.getBoundingClientRect();
                const transactionBounds = transactionsCard.getBoundingClientRect();
                const transactionsHeaderStyle =
                    getComputedStyle(transactionsHeader);

                return Math.abs(
                    chart.getBoundingClientRect().width
                    - chartSection.getBoundingClientRect().width,
                ) < 1
                    && Math.abs(overviewBounds.top - transactionBounds.top) < 1
                    && Math.abs(overviewBounds.bottom - transactionBounds.bottom) < 1
                    && transactionsHeaderStyle.paddingTop === '16px'
                    && transactionsHeaderStyle.paddingBottom === '16px'
                    && !transactionsHeader.classList.contains('h-12')
                    && transactionBounds.bottom <= innerHeight
                    && document.documentElement.scrollHeight
                        <= document.documentElement.clientHeight
                    && getComputedStyle(transactionsScroll).overflowY === 'auto'
                    && getComputedStyle(overviewScroll).overflowY === 'auto';
            })()
            JS)
        ->resize(390, 844)
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->assertScript(<<<'JS'
            (() => {
                const tabs = document.querySelector('[data-slot="tabs"]');
                const cardContent = tabs?.closest('[data-slot="card-content"]');
                const chart = cardContent?.querySelector('[data-slot="chart"]');

                if (tabs === null || cardContent === null || chart === null) {
                    return false;
                }

                const contentBounds = cardContent.getBoundingClientRect();
                const chartBounds = chart.getBoundingClientRect();
                const chartSectionBounds = chart.closest('section')?.getBoundingClientRect();

                return getComputedStyle(tabs).flexDirection === 'column'
                    && tabs.getBoundingClientRect().right <= contentBounds.right
                    && chartBounds.right <= contentBounds.right
                    && chartSectionBounds !== undefined
                    && Math.abs(chartBounds.width - chartSectionBounds.width) < 1;
            })()
            JS)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('all-currency charts and Categorization keep currencies readable', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Food',
    ]);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    $insurance = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Insurance',
    ]);
    $today = now()->toDateString();

    foreach ([
        ['currency' => 'PEN', 'amount_minor' => 19_000, 'category_id' => $dining->id],
        ['currency' => 'USD', 'amount_minor' => 2_000, 'category_id' => $dining->id],
        ['currency' => 'PEN', 'amount_minor' => 19_000, 'category_id' => null],
        ['currency' => 'USD', 'amount_minor' => 2_000, 'category_id' => null],
        ['currency' => 'PEN', 'amount_minor' => 1_900_000, 'category_id' => $insurance->id],
        ['currency' => 'USD', 'amount_minor' => 200_000, 'category_id' => $insurance->id],
    ] as $attributes) {
        Transaction::factory()->for($owner, 'owner')->spending()->create([
            ...$attributes,
            'occurred_on' => $today,
        ]);
    }

    $this->actingAs($owner);

    $page = visit("/breakdown?preset=custom&date_from={$today}&date_to={$today}");

    $page
        ->assertSee('S/ 190.00 + $ 20.00')
        ->assertSee('33.33% of transactions')
        ->assertScript(<<<JS
            (() => {
                const bars = document.querySelectorAll(
                    '[data-test="breakdown-day-{$today}"]',
                );
                const categorizationTrack = document.querySelector(
                    '[data-test="breakdown-categorization-bar"]',
                );
                const legend = document.querySelector(
                    '[data-test="chart-legend"]',
                );

                if (
                    bars.length !== 2
                    || categorizationTrack === null
                    || legend === null
                ) {
                    return false;
                }

                const pen = document.querySelector(
                    '[data-test="breakdown-day-{$today}"][data-currency="PEN"]',
                );
                const usd = document.querySelector(
                    '[data-test="breakdown-day-{$today}"][data-currency="USD"]',
                );

                if (pen === null || usd === null) {
                    return false;
                }

                const penBounds = pen.getBoundingClientRect();
                const usdBounds = usd.getBoundingClientRect();
                const barsTouch = Math.abs(penBounds.top - usdBounds.bottom) < 1
                    || Math.abs(usdBounds.top - penBounds.bottom) < 1;

                return Math.abs(penBounds.left - usdBounds.left) < 1
                    && Math.abs(penBounds.width - usdBounds.width) < 1
                    && barsTouch
                    && pen.getAttribute('data-stack-edge') === 'bottom'
                    && usd.getAttribute('data-stack-edge') === 'top'
                    && legend.textContent.includes('PEN')
                    && legend.textContent.includes('USD')
                    && categorizationTrack.children.length === 1;
            })()
            JS)
        ->click('[data-test="breakdown-tab-categories"]')
        ->assertScript(<<<JS
            (() => {
                const penTrack = document.querySelector(
                    '[data-test="breakdown-category-bar-{$food->id}-PEN"]',
                );
                const usdTrack = document.querySelector(
                    '[data-test="breakdown-category-bar-{$food->id}-USD"]',
                );
                const insurancePenTrack = document.querySelector(
                    '[data-test="breakdown-category-bar-{$insurance->id}-PEN"]',
                );
                const insuranceUsdTrack = document.querySelector(
                    '[data-test="breakdown-category-bar-{$insurance->id}-USD"]',
                );

                if (
                    penTrack === null
                    || usdTrack === null
                    || insurancePenTrack === null
                    || insuranceUsdTrack === null
                ) {
                    return false;
                }

                return penTrack.getBoundingClientRect().top
                    < usdTrack.getBoundingClientRect().top
                    && Math.abs(
                        penTrack.getBoundingClientRect().right
                        - usdTrack.getBoundingClientRect().right,
                    ) < 1
                    && Math.abs(
                        penTrack.getBoundingClientRect().right
                        - insurancePenTrack.getBoundingClientRect().right,
                    ) < 1
                    && Math.abs(
                        penTrack.getBoundingClientRect().right
                        - insuranceUsdTrack.getBoundingClientRect().right,
                    ) < 1
                    && penTrack.parentElement?.textContent.includes('S/ 190.00')
                    && usdTrack.parentElement?.textContent.includes('$ 20.00')
                    && insurancePenTrack.parentElement?.textContent.includes(
                        'S/ 19,000.00',
                    )
                    && insuranceUsdTrack.parentElement?.textContent.includes(
                        '$ 2,000.00',
                    );
            })()
            JS)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Spending over time draws refunds below a visible zero baseline', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 20_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => $yesterday,
        'amount_minor' => 5_000,
        'category_id' => null,
        'description' => 'Store refund',
    ]);

    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$yesterday}&date_to={$today}");

    $page
        ->assertSee('Categorization')
        ->assertSee('1 uncategorized')
        ->assertSee('S/ 50.00')
        ->assertSee('50% of transactions')
        ->assertScript(<<<JS
            (() => {
                const positive = document.querySelector(
                    '[data-test="breakdown-day-{$today}"][data-direction="positive"]',
                );
                const negative = document.querySelector(
                    '[data-test="breakdown-day-{$yesterday}"][data-direction="negative"]',
                );
                const baseline = document.querySelector('.spending-zero-baseline');

                if (positive === null || negative === null || baseline === null) {
                    return false;
                }

                const positiveBounds = positive.getBoundingClientRect();
                const negativeBounds = negative.getBoundingClientRect();
                const baselineBounds = baseline.getBoundingClientRect();

                return positiveBounds.height > 0
                    && negativeBounds.height > 0
                    && positiveBounds.bottom <= baselineBounds.bottom + 1
                    && negativeBounds.top >= baselineBounds.top - 1
                    && Number(negative.getAttribute('height')) > 0;
            })()
            JS)
        ->click('[data-test="breakdown-review-uncategorized"]')
        ->assertQueryStringHas('category', 'uncategorized')
        ->assertSee('Store refund')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Breakdown keeps empty selections useful across every overview tab', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner);

    $page = visit('/breakdown?currency=PEN');

    $page
        ->assertSee('Net spending')
        ->assertSee('Complete')
        ->assertSee('No spending or refunds')
        ->click('[data-test="breakdown-tab-categories"]')
        ->assertSee('No category spending in this selection.')
        ->click('[data-test="breakdown-tab-merchants"]')
        ->assertSee('No merchants in this selection.')
        ->assertNotPresent('[aria-label="Search every merchant"]')
        ->resize(390, 844)
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Breakdown keeps desktop cards in the viewport and scrolls their content', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();

    foreach (range(1, 24) as $index) {
        $category = Category::factory()->for($owner, 'owner')->create([
            'name' => sprintf('Category %02d', $index),
        ]);

        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $today,
            'amount_minor' => 100 + $index,
            'description' => sprintf('Merchant %02d', $index),
            'category_id' => $category->id,
        ]);
    }

    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}");

    $page
        ->resize(1280, 720)
        ->click('[data-test="breakdown-tab-categories"]')
        ->assertScript(<<<'JS'
            (() => {
                const inset = document.querySelector('[data-slot="sidebar-inset"]');
                const overview = document.querySelector(
                    '[data-test="breakdown-overview-card"]',
                );
                const transactions = document.querySelector(
                    '[data-test="breakdown-transactions-card"]',
                );
                const categories = document.querySelector(
                    '[data-test="breakdown-categories-scroll"]',
                );
                const transactionList = document.querySelector(
                    '[data-test="breakdown-transactions-scroll"]',
                );

                if (
                    inset === null
                    || overview === null
                    || transactions === null
                    || categories === null
                    || transactionList === null
                ) {
                    return false;
                }

                categories.scrollTop = 120;
                transactionList.scrollTop = 120;

                const overviewBounds = overview.getBoundingClientRect();
                const transactionBounds = transactions.getBoundingClientRect();

                const insetBounds = inset.getBoundingClientRect();

                return insetBounds.bottom <= innerHeight
                    && document.documentElement.scrollHeight
                        <= document.documentElement.clientHeight
                    && Math.abs(overviewBounds.height - transactionBounds.height) < 1
                    && overviewBounds.bottom <= innerHeight
                    && transactionBounds.bottom <= innerHeight
                    && categories.scrollHeight > categories.clientHeight
                    && categories.scrollTop > 0
                    && transactionList.scrollHeight > transactionList.clientHeight
                    && transactionList.scrollTop > 0;
            })()
            JS)
        ->click('[data-test="breakdown-tab-merchants"]')
        ->assertScript(<<<'JS'
            (() => {
                const merchants = document.querySelector(
                    '[data-test="breakdown-merchants-scroll"]',
                );

                if (merchants === null) {
                    return false;
                }

                merchants.scrollTop = 120;

                return merchants.scrollHeight > merchants.clientHeight
                    && merchants.scrollTop > 0;
            })()
            JS)
        ->resize(390, 844)
        ->assertScript(<<<'JS'
            (() => {
                const transactionList = document.querySelector(
                    '[data-test="breakdown-transactions-scroll"]',
                );

                if (transactionList === null) {
                    return false;
                }

                window.scrollTo(0, document.documentElement.scrollHeight);

                return document.documentElement.scrollHeight
                    > document.documentElement.clientHeight
                    && window.scrollY > 0
                    && getComputedStyle(transactionList).overscrollBehaviorY === 'auto';
            })()
            JS)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner classifies edits records and splits Transactions inside Breakdown', function () {
    $owner = User::factory()->create();
    $essentials = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Essentials',
    ]);
    $groceries = Category::factory()->for($owner, 'owner')->for($essentials, 'parent')->create([
        'name' => 'Weekly groceries and household supplies',
    ]);
    $household = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Household',
    ]);
    $today = now()->toDateString();
    $current = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 2_500,
        'description' => 'Café Central',
    ]);
    $historicalMatch = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 1_000,
        'description' => ' café central ',
        'category_id' => $household->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}");

    $page
        ->resize(390, 844)
        ->click('[aria-label="Category for Café Central"]')
        ->assertPresent('[aria-label="Search Categories"]')
        ->assertScript(<<<'JS'
            (() => {
                const trigger = document.querySelector(
                    '[aria-label="Category for Café Central"]',
                );
                const row = trigger?.closest('tr');

                if (row === null || row === undefined) {
                    return false;
                }

                row.dataset.heightBeforeCategorySelection = String(
                    row.getBoundingClientRect().height,
                );

                return true;
            })()
            JS)
        ->fill(
            '[cmdk-input][aria-label="Search Categories"]',
            'weekly groceries',
        )
        ->assertSee('Essentials > Weekly groceries and household supplies')
        ->click('@category-'.$current->id.'-option-'.$groceries->id)
        ->assertSee('Apply once')
        ->assertSee('Create rule')
        ->assertScript(<<<JS
            (() => {
                const trigger = document.querySelector(
                    '[aria-label="Category for Café Central"]',
                );
                const row = trigger?.closest('tr');
                const confirmation = document.querySelector(
                    '[data-test="category-confirmation-{$current->id}"]',
                );
                const popover = confirmation?.closest(
                    '[data-slot="popover-content"]',
                );

                if (
                    trigger === null
                    || row === null
                    || row === undefined
                    || confirmation === null
                    || popover === null
                    || popover === undefined
                ) {
                    return false;
                }

                return popover.contains(confirmation);
            })()
            JS)
        ->assertScript(<<<'JS'
            (() => {
                const trigger = document.querySelector(
                    '[aria-label="Category for Café Central"]',
                );
                const row = trigger?.closest('tr');

                if (row === null || row === undefined) {
                    return false;
                }

                return Math.abs(
                    row.getBoundingClientRect().height
                        - Number(row.dataset.heightBeforeCategorySelection),
                ) < 0.5;
            })()
            JS)
        ->assertScript(<<<'JS'
            (() => {
                const trigger = document.querySelector(
                    '[aria-label="Category for Café Central"]',
                );
                const row = trigger?.closest('tr');

                if (trigger === null || row === null || row === undefined) {
                    return false;
                }

                const rowBounds = row.getBoundingClientRect();

                return trigger.textContent.includes(
                    'Weekly groceries and household supplies',
                )
                    && rowBounds.left >= 0
                    && rowBounds.right <= innerWidth
                    && document.documentElement.scrollWidth
                        <= document.documentElement.clientWidth;
            })()
            JS);

    expect($current->refresh())
        ->category_id->toBeNull()
        ->merchant_rule_id->toBeNull();

    $page
        ->click('[data-test="create-merchant-rule-'.$current->id.'"]')
        ->assertSee('future exact matches will follow this Category')
        ->resize(1280, 720)
        ->click('[data-test="breakdown-transaction-'.$current->id.'"]')
        ->press('Edit Transaction')
        ->fill('Merchant or description', 'Café Central Lima')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->press('Split by Category')
        ->fill('[name="line_items[0][line_total]"]', '20.00')
        ->fill('[name="line_items[1][line_total]"]', '5.00')
        ->select(
            '[name="line_items[0][category_id]"]',
            (string) $groceries->id,
        )
        ->select(
            '[name="line_items[1][category_id]"]',
            (string) $household->id,
        )
        ->assertSee('Amounts reconcile exactly')
        ->press('Save Category split')
        ->assertSee('Category split saved.')
        ->press('Close')
        ->press('Add Transaction')
        ->fill('#transaction-amount', '7.50')
        ->fill('#transaction-description', 'Manual bakery')
        ->press('Save Transaction')
        ->assertSee('Transaction recorded.')
        ->assertSee('Manual bakery')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($current->refresh())
        ->description->toBe('Café Central Lima')
        ->category_id->toBe($groceries->id)
        ->merchant_rule_id->toBeNull()
        ->and($historicalMatch->refresh()->category_id)->toBe($groceries->id)
        ->and(MerchantRule::query()->whereBelongsTo($owner, 'owner')->exists())->toBeTrue()
        ->and(ReceiptBreakdown::query()->whereBelongsTo($current)->exists())->toBeTrue()
        ->and(Transaction::query()->where('description', 'Manual bakery')->exists())->toBeTrue();
});

test('the owner edits a Transaction in the Breakdown dialog', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 2_500,
        'description' => 'Imported purchase',
        'instrument_label' => 'Visa',
        'instrument_last_four' => '4242',
    ]);
    $this->actingAs($owner);

    visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}")
        ->click('[data-test="breakdown-transaction-'.$transaction->id.'"]')
        ->press('Edit Transaction')
        ->assertPresent('#transaction-amount')
        ->assertSee('Transaction Kind')
        ->fill('#transaction-description', 'Corrected purchase')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertNoJavaScriptErrors();

    expect($transaction->refresh()->description)->toBe('Corrected purchase')
        ->and($transaction->instrument_label)->toBe('Visa')
        ->and($transaction->instrument_last_four)->toBe('4242');
});

test('the owner records a categorized Spending with the shared editor', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $today = now()->toDateString();
    $this->actingAs($owner);

    visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}")
        ->press('Add Transaction')
        ->assertValue('#transaction-date', $today)
        ->assertValue('#transaction-kind', 'spending')
        ->assertValue('#transaction-direction', 'debit')
        ->fill('#transaction-amount', '12.50')
        ->fill('#transaction-description', 'Cash groceries')
        ->click('@transaction-category-trigger')
        ->click('@transaction-category-option-'.$category->id)
        ->click('Optional payment source')
        ->fill('#transaction-instrument-label', 'Cash wallet')
        ->fill('#transaction-last-four', '1234')
        ->press('Save Transaction')
        ->assertSee('Transaction recorded.')
        ->assertNoJavaScriptErrors();

    $transaction = Transaction::query()->sole();

    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->instrument_label)->toBe('Cash wallet')
        ->and($transaction->instrument_last_four)->toBe('1234');
});

test('new Transaction Kinds set editable direction defaults and reveal their classification', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();
    $this->actingAs($owner);

    visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}")
        ->press('Add Transaction')
        ->select('#transaction-kind', 'income')
        ->assertValue('#transaction-direction', 'credit')
        ->assertPresent('#transaction-income-source')
        ->assertNotPresent('#transaction-category-trigger')
        ->select('#transaction-kind', 'transfer')
        ->assertValue('#transaction-direction', 'debit')
        ->assertPresent('#transaction-transfer-purpose')
        ->select('#transaction-kind', 'refund')
        ->assertValue('#transaction-direction', 'credit')
        ->select('#transaction-direction', 'debit')
        ->assertValue('#transaction-direction', 'debit')
        ->press('Cancel')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->doesntExist())->toBeTrue();
});

test('changing Spending to an internal Transfer explains and confirms Category removal', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $today = now()->toDateString();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'description' => 'Imported movement',
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}");

    $page
        ->click('[data-test="breakdown-transaction-'.$transaction->id.'"]')
        ->press('Edit Transaction')
        ->select('#transaction-kind', 'transfer')
        ->assertValue('#transaction-direction', 'debit')
        ->assertSee('Other transfer includes movements between your accounts.')
        ->press('Save Transaction')
        ->assertSee('Category: Groceries')
        ->press('Continue editing');

    expect($transaction->refresh()->kind->value)->toBe('spending');

    $page->press('Save Transaction')->assertSee('Remove and save');
    $page->script('document.querySelector("[data-slot=alert-dialog-action]").click()');
    $page->assertSee('Transaction updated.')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertNoJavaScriptErrors();

    expect($transaction->refresh()->kind->value)->toBe('transfer')
        ->and($transaction->direction->value)->toBe('debit')
        ->and($transaction->transfer_purpose->value)->toBe('internal')
        ->and($transaction->category_id)->toBeNull();
});

test('a Refund can be corrected to Income or an internal Transfer in one edit', function (string $kind) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Shopping']);
    $today = now()->toDateString();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => $today,
        'description' => 'Imported reimbursement',
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        'original_spending_id' => $spending->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}");

    $page
        ->click('[data-test="breakdown-transaction-'.$refund->id.'"]')
        ->press('Edit Transaction')
        ->select('#transaction-kind', $kind)
        ->assertValue('#transaction-direction', 'credit')
        ->assertSee($kind === 'income' ? 'Income Source' : 'Transfer Purpose')
        ->press('Save Transaction')
        ->assertSee('Category: Shopping')
        ->assertSee('Original Spending link: Transaction #'.$spending->id);

    $page->script('document.querySelector("[data-slot=alert-dialog-action]").click()');
    $page->assertSee('Transaction updated.')->assertNoJavaScriptErrors();

    expect($refund->refresh()->kind->value)->toBe($kind)
        ->and($refund->direction->value)->toBe('credit')
        ->and($refund->category_id)->toBeNull()
        ->and($refund->original_spending_id)->toBeNull();
})->with(['income', 'transfer']);

test('an amount edit keeps the split until its removal is confirmed', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $today = now()->toDateString();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 2_500,
        'description' => 'Split purchase',
    ]);
    $split = ReceiptBreakdown::factory()->for($transaction)->create();
    LineItem::factory()->for($split)->create([
        'line_total_minor' => 2_500,
        'category_id' => $category->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}");

    $page
        ->click('[data-test="breakdown-transaction-'.$transaction->id.'"]')
        ->press('Edit Transaction')
        ->assertSee('Category split')
        ->assertSee('Groceries')
        ->assertNotPresent('#transaction-category-trigger')
        ->fill('#transaction-amount', '30.00')
        ->press('Save Transaction')
        ->assertSee('Category split and its allocations')
        ->press('Continue editing');

    expect($transaction->refresh()->amount_minor)->toBe(2_500)
        ->and($transaction->receiptBreakdown()->exists())->toBeTrue();

    $page->press('Save Transaction');
    $page->script('document.querySelector("[data-slot=alert-dialog-action]").click()');
    $page->assertSee('Transaction updated.')->assertNoJavaScriptErrors();

    expect($transaction->refresh()->amount_minor)->toBe(3_000)
        ->and($transaction->receiptBreakdown()->exists())->toBeFalse();
});

test('an unchanged split amount saves without confirmation', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'amount_minor' => 2_500,
        'description' => 'Split purchase',
    ]);
    $split = ReceiptBreakdown::factory()->for($transaction)->create();
    LineItem::factory()->for($split)->create(['line_total_minor' => 2_500]);
    $this->actingAs($owner);

    visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}")
        ->click('[data-test="breakdown-transaction-'.$transaction->id.'"]')
        ->press('Edit Transaction')
        ->fill('#transaction-amount', '25')
        ->fill('#transaction-description', 'Updated split purchase')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertNoJavaScriptErrors();

    expect($transaction->refresh()->receiptBreakdown()->exists())->toBeTrue()
        ->and($transaction->description)->toBe('Updated split purchase');
});

test('validation keeps the draft and opens the optional section with an error', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'description' => 'Imported purchase',
        'instrument_label' => 'Visa',
        'instrument_last_four' => '4242',
    ]);
    $this->actingAs($owner);

    visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}")
        ->click('[data-test="breakdown-transaction-'.$transaction->id.'"]')
        ->press('Edit Transaction')
        ->fill('#transaction-description', 'Draft correction')
        ->click('Optional payment source')
        ->fill('#transaction-last-four', 'abcd')
        ->click('Optional payment source')
        ->press('Save Transaction')
        ->assertSee('The instrument last four field format is invalid.')
        ->assertValue('#transaction-description', 'Draft correction')
        ->assertScript('document.querySelector("#transaction-last-four").closest("details").open')
        ->assertNoJavaScriptErrors();

    expect($transaction->refresh()->description)->toBe('Imported purchase')
        ->and($transaction->instrument_last_four)->toBe('4242');
});

test('the phone editor fills the viewport and cancellation leaves the record unchanged', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => $today,
        'description' => 'Cash purchase',
    ]);
    $this->actingAs($owner);

    visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}")
        ->resize(390, 844)
        ->click('[data-test="breakdown-transaction-'.$transaction->id.'"]')
        ->press('Edit Transaction')
        ->assertScript(<<<'JS'
            (() => {
                const dialog = document.querySelector('[data-slot="dialog-content"]');
                const bounds = dialog?.getBoundingClientRect();

                return bounds !== undefined
                    && bounds.left <= 1
                    && bounds.top <= 1
                    && bounds.width >= innerWidth - 2
                    && bounds.height >= innerHeight - 2;
            })()
            JS)
        ->fill('#transaction-description', 'Unsaved draft')
        ->press('Cancel')
        ->assertNoJavaScriptErrors();

    expect($transaction->refresh()->description)->toBe('Cash purchase');
});
