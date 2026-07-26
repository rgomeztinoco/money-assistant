<?php

use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('filters, selection, and scroll context persist while correcting a Transaction in the inspector', function () {
    $owner = User::factory()->create();
    Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
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
        ->select('Filter review state', 'outstanding')
        ->press('Apply filters')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertSee('Neighborhood market')
        ->assertDontSee('Unrelated pharmacy')
        ->script('window.scrollTo(0, document.body.scrollHeight)');

    $page
        ->press('Inspect')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertQueryStringHas('selected')
        ->assertSee('Current values')
        ->assertSee('Included in spending totals')
        ->assertSee('Provenance')
        ->assertScript('window.scrollY > 0')
        ->fill('Correct Merchant or description', 'Neighborhood market Lima')
        ->press('Save Correction')
        ->assertSee('Correction saved.')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertSee('Neighborhood market Lima')
        ->assertSee('Correction · Merchant or description')
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
        ->assertSee('Current values')
        ->press('Close')
        ->assertQueryStringHas('inspector', 'closed')
        ->assertDontSee('Current values')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
