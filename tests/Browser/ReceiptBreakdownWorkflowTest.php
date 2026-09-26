<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner saves replaces and removes a Category split from Transactions', function () {
    $owner = User::factory()->create();
    $shopping = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->spending()->pen()->create([
        'amount_minor' => 2_500,
        'description' => 'Neighborhood market',
        'category_id' => $shopping->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions?search=Neighborhood');

    $page
        ->click('[data-test="transaction-'.$transaction->id.'"]')
        ->click('[data-slot="dropdown-menu-item"]:has-text("Split by Category")')
        ->assertSee('Split Neighborhood market by Category')
        ->assertDontSee('Manual itemization')
        ->fill('[name="line_items[0][line_total]"]', '20.00')
        ->assertSee('S/ 5.00 remaining')
        ->fill('[name="line_items[1][line_total]"]', '5.00')
        ->assertSee('Amounts reconcile exactly')
        ->select('[name="line_items[0][category_id]"]', (string) $groceries->id)
        ->press('Save Category split')
        ->assertSee('Category split saved.')
        ->assertSee('Replace Category split')
        ->assertQueryStringHas('search', 'Neighborhood');

    expect(ReceiptBreakdown::query()->count())->toBe(1)
        ->and(ReceiptBreakdown::query()->sole()->lineItems()->count())->toBe(2);

    $page
        ->fill('[name="line_items[0][line_total]"]', '15.00')
        ->fill('[name="line_items[1][line_total]"]', '10.00')
        ->press('Replace Category split')
        ->assertSee('Category split saved.')
        ->press('Remove Category split')
        ->assertSee('Category split removed.')
        ->assertDontSee('Manual itemization')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(ReceiptBreakdown::query()->count())->toBe(0);
});
