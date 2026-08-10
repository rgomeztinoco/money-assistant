<?php

use App\Actions\ReceiptReconciliation\UpdateReceiptBreakdownDraft;
use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner attaches edits and confirms a Receipt Breakdown in the Transaction inspector', function () {
    $owner = User::factory()->create();
    $shopping = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
        'merchant_description' => 'Neighborhood market',
        'category_id' => $shopping->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $this->actingAs($owner);

    $page = visit("/transactions?search=Neighborhood&selected={$transaction->id}");

    $page
        ->assertSee('Manual itemization')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->press('Create Receipt Breakdown')
        ->assertSee('Draft revision 1')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertSee('Draft Line Items do not affect reports.');

    $lineItem = LineItem::query()->sole();

    $page
        ->fill('Signed total in minor units', '2500')
        ->select("#receipt-line-{$lineItem->line_item_id}-category", (string) $groceries->id)
        ->assertSee('Save these edits as a new draft revision before confirming.')
        ->press('Save draft revision')
        ->assertSee('Draft revision 2')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->press('Confirm exact breakdown')
        ->assertSee('Confirmed revision 2')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertSee('These Line Items replace the Transaction Category in reports')
        ->press('Remove from reports')
        ->assertSee('Draft revision 3')
        ->assertSee('This draft and its Line Items remain recoverable in trash for 30 days.')
        ->press('Move draft to trash')
        ->assertSee('Receipt Breakdown draft moved to trash for 30 days.')
        ->assertSee('Receipt Breakdowns in trash')
        ->press('Restore draft revision 3')
        ->assertSee('Receipt Breakdown restored from trash.')
        ->assertSee('Draft revision 3')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a stale browser edit is rejected and re-presents the current draft revision', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
        'merchant_description' => 'Neighborhood market',
    ]);
    $draft = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->draft()->create();
    $lineItem = LineItem::factory()->for($draft)->create([
        'description' => 'Coffee beans',
        'line_total_minor' => 2500,
    ]);
    $this->actingAs($owner);
    $page = visit("/transactions?search=Neighborhood&selected={$transaction->id}");

    $page->assertSee('Draft revision 1');

    app(UpdateReceiptBreakdownDraft::class)->handle($owner, $draft, 1, [[
        'id' => $lineItem->line_item_id,
        'description' => 'Fresh coffee beans',
        'line_total_minor' => 2500,
        'category_id' => null,
    ]]);

    $page
        ->press('Save draft revision')
        ->assertSee('The draft changed. Review revision 2 and try again.')
        ->assertSee('Draft revision 2')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
