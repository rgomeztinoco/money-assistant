<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the Dashboard directs attention into filtered owner workflows', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    Transaction::factory()->purchase()->usd()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 1_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->purchase()->pen()->provisional([
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
        ->assertDontSee('Combined spending')
        ->assertSee('Review Queue')
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
