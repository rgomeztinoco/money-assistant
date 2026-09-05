<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
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
        ->assertSee(str($yesterday)->after('-')->toString())
        ->assertSee(str($today)->after('-')->toString())
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
                    || overviewScroll === null
                ) {
                    return false;
                }

                const overviewBounds = overviewCard.getBoundingClientRect();
                const transactionBounds = transactionsCard.getBoundingClientRect();

                return Math.abs(
                    chart.getBoundingClientRect().width
                    - chartSection.getBoundingClientRect().width,
                ) < 1
                    && Math.abs(overviewBounds.top - transactionBounds.top) < 1
                    && Math.abs(overviewBounds.bottom - transactionBounds.bottom) < 1
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
        ->assertPresent('[aria-label="Search categories"]')
        ->fill('[aria-label="Search categories"]', 'weekly groceries')
        ->assertSee('Essentials')
        ->assertSee('Weekly groceries and household supplies')
        ->assertDontSee('Essentials > Weekly groceries and household supplies')
        ->press('Weekly groceries and household supplies')
        ->assertSee('Apply once')
        ->assertSee('Create rule')
        ->wait(1)
        ->assertScript(<<<JS
            (() => {
                const trigger = document.querySelector(
                    '[aria-label="Category for Café Central"]',
                );
                const row = trigger?.closest('tr');
                const confirmation = document.querySelector(
                    '[data-test="category-confirmation-{$current->id}"]',
                );

                if (
                    trigger === null
                    || row === null
                    || row === undefined
                    || confirmation === null
                ) {
                    return false;
                }

                const rowBounds = row.getBoundingClientRect();
                const popoverBounds = confirmation
                    .closest('[data-slot="popover-content"]')
                    ?.getBoundingClientRect();

                return trigger.textContent.includes(
                    'Weekly groceries and household supplies',
                )
                    && confirmation.closest('tr') === null
                    && popoverBounds !== undefined
                    && popoverBounds.left >= 0
                    && popoverBounds.right <= innerWidth
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
        ->fill('#manual-amount', '7.50')
        ->fill('#manual-description', 'Manual bakery')
        ->press('Record Transaction')
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
