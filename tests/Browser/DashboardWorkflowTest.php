<?php

use App\Models\Category;
use App\Models\GmailConnection;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the Dashboard directs attention into filtered owner workflows', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 1_000,
        'description' => 'Coffee shop',
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->provisional([
        ReviewableTransactionField::Description,
    ])->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 2_500,
        'description' => 'Neighborhood market',
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => now()->subMonthNoOverflow()->toDateString(),
        'amount_minor' => 500,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => now()->subMonthNoOverflow()->toDateString(),
        'amount_minor' => 1_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 5_000,
        'description' => 'Salary',
    ]);
    Transaction::factory()->for($owner, 'owner')->transfer()->pen()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 1_500,
        'transfer_purpose' => 'savings',
        'description' => 'Moved to savings',
    ]);
    GmailConnection::factory()->for($owner, 'owner')->create([
        'gmail_account_identity' => 'owner@example.com',
    ]);
    $this->actingAs($owner);

    $page = visit('/dashboard')->resize(390, 844);

    $page
        ->assertSee('Net Spending')
        ->assertSee('Income')
        ->assertSee('Moved to Savings')
        ->assertSee('$ 10.00')
        ->assertSee('S/ 25.00')
        ->assertSee('S/ 50.00')
        ->assertSee('S/ 15.00')
        ->assertSee('100% more than the previous period')
        ->assertSee('150% more than the previous period')
        ->assertSee('What changed')
        ->assertSee($category->name)
        ->assertDontSee('Combined spending')
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->assertSee('Recent Transactions')
        ->assertSee('Coffee shop')
        ->assertSee('Neighborhood market')
        ->assertSee('Open Breakdown')
        ->assertSee('Gmail')
        ->assertSee('Connected')
        ->assertSee('owner@example.com')
        ->click('[data-test="dashboard-spending-usd"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas(
            'date_from',
            now()->startOfMonth()->toDateString(),
        )
        ->assertQueryStringHas('date_to', now()->toDateString());

    $page = visit('/dashboard')->resize(1280, 720);

    $page
        ->click('[data-test="dashboard-comparison-usd"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'USD')
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas(
            'date_from',
            now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        )
        ->assertQueryStringHas(
            'date_to',
            now()->subMonthNoOverflow()->toDateString(),
        );

    $page = visit('/dashboard');

    $page
        ->click('[data-test="dashboard-category-pen-'.$category->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('category', (string) $category->id)
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas(
            'date_from',
            now()->startOfMonth()->toDateString(),
        )
        ->assertQueryStringHas('date_to', now()->toDateString());

    $page = visit('/dashboard');

    $page
        ->click('[data-test="dashboard-category-previous-pen-'.$category->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('category', (string) $category->id)
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas(
            'date_from',
            now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        )
        ->assertQueryStringHas(
            'date_to',
            now()->subMonthNoOverflow()->toDateString(),
        );

    $page = visit('/dashboard');

    $page
        ->click('[data-test="dashboard-review-link"]')
        ->assertPathIs('/breakdown');

    $page = visit('/dashboard');

    $page
        ->click('[data-test="dashboard-gmail-link"]')
        ->assertPathIs('/settings/connections');

    $page = visit('/dashboard');

    $page
        ->click('[data-test="nav-reports"]')
        ->assertPathIs('/reports/PEN')
        ->assertSee('PEN spending report')
        ->click('[data-test="report-switch-usd"]')
        ->assertPathIs('/reports/USD')
        ->assertSee('USD spending report')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('navigation exposes only retained personal-finance destinations', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transactionAwaitingReview = Transaction::factory()->for($owner, 'owner')->spending()->provisional([
        ReviewableTransactionField::Description,
    ])->create([
        'category_id' => $category->id,
    ]);
    $this->actingAs($owner);

    $page = visit('/dashboard');

    $page
        ->assertTitle('Dashboard - Money Assistant')
        ->assertSee('Everyday')
        ->assertSee('Dashboard')
        ->assertSeeIn('[data-test="nav-breakdown"]', 'Breakdown')
        ->assertSee('Reports')
        ->assertSee('Manage & automate')
        ->assertSee('Statement Imports')
        ->assertSee('Categories')
        ->assertSee('Merchant Rules')
        ->assertSee('Parser Profiles');

    $transactionAwaitingReview->update(['provisional_fields' => []]);

    $page
        ->click('[data-test="nav-breakdown"]')
        ->assertPathIs('/breakdown')
        ->assertTitle('Breakdown - Money Assistant')
        ->assertAttribute('[data-test="nav-breakdown"]', 'data-active', 'true')
        ->assertSeeIn('[data-slot="breadcrumb-page"]', 'Breakdown')
        ->click('[data-test="sidebar-menu-button"]')
        ->assertSee('Settings')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Reports explain spending with responsive charts and supporting Transaction links', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    $fees = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Fees',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-20',
        'amount_minor' => 2_000,
        'category_id' => $food->id,
    ]);
    $itemized = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 3_000,
        'category_id' => $food->id,
    ]);
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($itemized)->create();
    LineItem::factory()->for($breakdown)->create([
        'description' => 'Lunch',
        'line_total_minor' => 2_999,
        'category_id' => $dining->id,
    ]);
    LineItem::factory()->for($breakdown)->create([
        'description' => 'Fee',
        'line_total_minor' => 1,
        'category_id' => $fees->id,
    ]);
    $this->actingAs($owner);
    $reportUrl = '/reports/PEN?date_from=2026-08-01&date_to=2026-08-20';

    $page = visit($reportUrl)->resize(390, 844);

    $page
        ->assertSee('50% more than the previous period')
        ->assertSee('Monthly history')
        ->assertSee('Category composition')
        ->assertSee('August 2026')
        ->assertSee('Food')
        ->assertSee('Dining')
        ->assertSee('Fees')
        ->assertPresent('[data-test="report-month-2026-08"] [data-test="chart-bar"]')
        ->assertPresent('[data-test="report-category-'.$food->id.'"] [data-test="chart-bar"]')
        ->assertScript(
            'parseFloat(document.querySelector(\'[data-test="report-category-'.$fees->id.'"] [data-test="chart-bar"]\').style.width) > 0',
        )
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->assertNoAccessibilityIssues()
        ->click('[data-test="report-month-2026-08"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-20');

    $page = visit($reportUrl);

    $page
        ->click('[data-test="report-category-'.$dining->id.'"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('currency', 'PEN')
        ->assertQueryStringHas('category', (string) $dining->id)
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas('date_from', '2026-08-01')
        ->assertQueryStringHas('date_to', '2026-08-20')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit($reportUrl)->resize(1280, 720);

    $page
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->click('[data-test="report-month-2026-07"]')
        ->assertPathIs('/breakdown')
        ->assertQueryStringHas('preset', 'custom')
        ->assertQueryStringHas('date_from', '2026-07-01')
        ->assertQueryStringHas('date_to', '2026-07-31');
});
