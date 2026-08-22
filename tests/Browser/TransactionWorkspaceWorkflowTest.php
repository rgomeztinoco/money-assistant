<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('filters, selection, and scroll context persist while directly editing a Transaction in the inspector', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
            'merchant_description' => 'Neighborhood market',
            'occurred_on' => '2026-07-20',
        ]);
    Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Unrelated pharmacy',
        'occurred_on' => '2026-07-21',
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->fill('Merchant or description', 'Neighborhood')
        ->press('Advanced filters')
        ->select('Filter review state', 'outstanding')
        ->press('Apply filters')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertSee('Neighborhood market')
        ->assertDontSee('Unrelated pharmacy');

    $page->script(
        "document.body.style.minHeight = '2500px'; window.scrollTo(0, document.body.scrollHeight)",
    );

    $page->assertScript('window.scrollY > 0');

    $page
        ->press('Inspect')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertQueryStringHas('selected')
        ->assertSee('Edit current Transaction')
        ->assertSee('Included in spending totals')
        ->press('Advanced details')
        ->assertSee('Provenance')
        ->fill('Edit merchant or description', 'Neighborhood market Lima')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertSee('Neighborhood market Lima')
        ->assertSee('Review clear')
        ->press('Close')
        ->assertScript('window.scrollY > 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the Transaction workspace stays actionable without horizontal scrolling on mobile', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'merchant_description' => 'Mobile market',
        'amount_minor' => 1_250,
        'occurred_on' => '2026-08-21',
    ]);
    Transaction::factory()->count(25)->for($owner, 'owner')->purchase()->usd()->create([
        'merchant_description' => 'Earlier mobile market',
        'occurred_on' => '2026-08-20',
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions')->on()->iPhone14Pro();

    $page
        ->assertSee('Mobile market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Inspect')
        ->assertSee('Transaction summary')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Close')
        ->press('Void')
        ->assertSee('Transaction voided.')
        ->press('Next')
        ->assertQueryStringHas('page', '2')
        ->assertSee('Earlier mobile market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Previous')
        ->assertSee('Mobile market')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the Review Queue inspector can be dismissed without immediately reopening', function () {
    $owner = User::factory()->create();
    Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create(['merchant_description' => 'Review me']);
    $this->actingAs($owner);

    $page = visit('/review-queue');

    $page
        ->assertSee('Edit current Transaction')
        ->press('Close')
        ->assertQueryStringHas('inspector', 'closed')
        ->assertDontSee('Edit current Transaction')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
