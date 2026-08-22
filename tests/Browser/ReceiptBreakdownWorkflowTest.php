<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner saves replaces and removes a Receipt Breakdown in the Transaction inspector', function () {
    $owner = User::factory()->create();
    $shopping = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2_500,
        'merchant_description' => 'Neighborhood market',
        'category_id' => $shopping->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $this->actingAs($owner);

    $page = visit("/transactions?search=Neighborhood&selected={$transaction->id}");

    $page
        ->assertSee('Manual itemization')
        ->assertSee('Quantity and unit price are optional context only.')
        ->fill('[name="line_items[0][line_total]"]', '20.00')
        ->assertSee('S/ 5.00 remaining')
        ->press('Add Line Item')
        ->fill('[name="line_items[1][description]"]', 'Bread')
        ->fill('[name="line_items[1][line_total]"]', '5.00')
        ->assertSee('Reconciled exactly')
        ->select('[name="line_items[0][category_id]"]', (string) $groceries->id)
        ->press('Save Receipt Breakdown')
        ->assertSee('Receipt Breakdown saved.')
        ->assertSee('Current itemization')
        ->assertSee('Replace Receipt Breakdown')
        ->assertQueryStringHas('search', 'Neighborhood');

    expect(ReceiptBreakdown::query()->count())->toBe(1)
        ->and(ReceiptBreakdown::query()->sole()->lineItems()->count())->toBe(2);

    $page
        ->fill('Description', 'Fresh coffee beans')
        ->press('Replace Receipt Breakdown')
        ->wait(1)
        ->assertSee('Receipt Breakdown saved.')
        ->press('Remove Receipt Breakdown')
        ->assertSee('Receipt Breakdown removed.')
        ->assertSee('Manual itemization')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(ReceiptBreakdown::query()->count())->toBe(0);
});
