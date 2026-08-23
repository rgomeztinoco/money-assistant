<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
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

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$yesterday}&date_to={$today}");

    $page
        ->assertSee('Where the money went')
        ->assertSee('Daily spikes')
        ->click('[data-test="breakdown-category-'.$food->id.'"]')
        ->assertQueryStringHas('category', (string) $food->id)
        ->assertSee('Dining')
        ->assertSee('Neighborhood market')
        ->assertSee('Corner cafe')
        ->assertDontSee('Bus pass')
        ->click('[data-test="breakdown-day-'.$today.'"]')
        ->assertQueryStringHas('day', $today)
        ->assertSee('Neighborhood market')
        ->assertDontSee('Corner cafe')
        ->assertDontSee('Bus pass')
        ->assertSee('S/ 25.00')
        ->resize(390, 844)
        ->assertScript(
            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        )
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner classifies edits records and splits Transactions inside Breakdown', function () {
    $owner = User::factory()->create();
    $groceries = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
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

    $page = visit("/breakdown?currency=PEN&preset=custom&date_from={$today}&date_to={$today}&selected={$current->id}");

    $page
        ->assertSee('Update 1 other matching historical Transaction')
        ->select(
            '#transaction-'.$current->id.'-category',
            (string) $groceries->id,
        )
        ->click('#transaction-'.$current->id.'-matching')
        ->press('Save')
        ->assertSee('2 matching Transactions updated')
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
        ->fill('Amount', '7.50')
        ->fill('Merchant or short description', 'Manual bakery')
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
        ->and(ReceiptBreakdown::query()->whereBelongsTo($current)->exists())->toBeTrue()
        ->and(Transaction::query()->where('description', 'Manual bakery')->exists())->toBeTrue();
});
