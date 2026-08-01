<?php

use App\Currency;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner sees a factual completed-month comparison without forecast claims', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);
    $selectedMonth = now()->subMonthNoOverflow()->startOfMonth();
    $baselineMonths = collect([4, 3, 2])
        ->map(fn (int $monthsAgo) => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth());

    foreach ($baselineMonths as $index => $month) {
        Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
            'occurred_on' => $month->addDays(9)->toDateString(),
            'amount_minor' => [9_000, 12_000, 15_000][$index],
            'category_id' => $category->id,
        ]);
    }

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => $selectedMonth->addDays(9)->toDateString(),
        'amount_minor' => 15_000,
        'category_id' => $category->id,
    ]);
    $this->actingAs($owner);

    $page = visit('/insights?'.http_build_query([
        'date_from' => $selectedMonth->toDateString(),
        'date_to' => $selectedMonth->endOfMonth()->toDateString(),
    ]));

    $page
        ->assertSee('Spending Insights')
        ->assertSee('Completed spending')
        ->assertSee('Spending Baseline')
        ->assertSee('Established')
        ->assertSee('Completed-month comparison')
        ->assertSee('S/ 150.00')
        ->assertSee('+25.00%')
        ->assertSee('Groceries')
        ->assertSee('Category Targets')
        ->assertDontSee('on track')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
