<?php

use App\Currency;
use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner explicitly approves revises and retires a Category Target', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);

    foreach ([3 => 9_000, 2 => 12_000, 1 => 15_000] as $monthsAgo => $amountMinor) {
        Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
            'occurred_on' => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(9),
            'amount_minor' => $amountMinor,
            'category_id' => $category->id,
        ]);
    }

    $this->actingAs($owner);
    $page = visit(route('insights.index'));
    $startingMonth = now()->addMonthNoOverflow()->startOfMonth()->format('Y-m');

    $page
        ->assertSee('Approve a Category Target')
        ->assertValue('#target-amount', '120.00')
        ->fill('#target-amount', '100.00')
        ->fill('#target-starts-on', $startingMonth)
        ->press('Approve Target')
        ->assertSee('Category Target activated.')
        ->assertSee('S/ 100.00 each month')
        ->assertSee('Scheduled')
        ->assertSee("Starts in {$startingMonth}")
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $target = CategoryTarget::query()->whereBelongsTo($owner, 'owner')->sole();

    $page
        ->press('Revise')
        ->fill("#target-{$target->id}-amount", '80.00')
        ->press('Save revision')
        ->assertSee('Category Target revised.')
        ->assertSee('S/ 80.00 each month')
        ->press('Retire')
        ->press('Schedule retirement')
        ->assertSee('Category Target retirement scheduled.')
        ->assertSee('Retired')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
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
