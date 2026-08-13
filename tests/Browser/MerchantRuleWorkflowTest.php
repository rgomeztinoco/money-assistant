<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner creates a Merchant Rule that categorizes a future Transaction', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);
    $this->actingAs($owner);

    visit('/merchant-rules')
        ->assertSee('Existing Transactions never change.')
        ->fill('Merchant', 'CAFÉ—Central')
        ->select('Category', 'Groceries')
        ->select('Transaction kind', 'Purchase')
        ->select('Currency', 'PEN')
        ->press('Create Merchant Rule')
        ->assertSee('Merchant Rule created.')
        ->assertSee('Exact key: café central')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    visit('/transactions')
        ->fill('Amount in minor units', '1250')
        ->fill('Merchant or short description', "cafe\u{0301} central")
        ->select('Currency', 'PEN')
        ->select('Transaction kind', 'purchase')
        ->press('Record Transaction')
        ->assertSee('Transaction recorded.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(MerchantRule::query()->sole()->merchant_key)->toBe('café central')
        ->and(Transaction::query()->sole())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule);
});
