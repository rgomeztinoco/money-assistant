<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner creates a child and opens its taxonomy group', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    Category::factory()->for($owner, 'owner')->create(['name' => 'Utilities']);
    $this->actingAs($owner);

    $page = visit('/categories');

    $page
        ->assertSee('Uncategorized remains a system state')
        ->assertSeeIn('@category-table-title', 'All Categories')
        ->click('[aria-label="Actions for Food"]')
        ->click('Add subcategory')
        ->fill('#new-category-name', 'Dining out')
        ->press('Create Category')
        ->assertSee('Category created.')
        ->assertSee('Dining out')
        ->click('@category-browser-'.$food->id)
        ->assertSeeIn('@category-table-title', 'Food')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the mobile Category selector navigates the taxonomy', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    Category::factory()->for($owner, 'owner')->create(['name' => 'Utilities']);
    $this->actingAs($owner);

    visit('/categories')
        ->on()
        ->mobile()
        ->assertSeeIn('@category-table-title', 'All Categories')
        ->click('@mobile-category-browser-trigger')
        ->fill('[cmdk-input][aria-label="Search Categories"]', 'food')
        ->click('@mobile-category-browser-option-'.$food->id)
        ->assertSeeIn('@category-table-title', 'Food')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the Category dialog creates and selects a missing parent', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    visit('/categories')
        ->press('New Category')
        ->fill('#new-category-name', 'Dining out')
        ->click('@new-category-parent-trigger')
        ->click('@new-category-parent-create-option')
        ->fill('#new-category-parent-new-name', 'Food')
        ->press('Create Parent Category')
        ->assertSeeIn('@new-category-parent-trigger', 'Food')
        ->press('Create Category')
        ->assertSee('Category created.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $food = Category::query()->where('name', 'Food')->sole();

    expect($food->parent_id)->toBeNull()
        ->and(Category::query()->where('name', 'Dining out')->sole()->parent_id)
        ->toBe($food->id);
});

test('archive confirmation names affected children and Merchant Rules', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Dining']);
    MerchantRule::factory()->for($owner, 'owner')->for($dining)->create([
        'merchant' => 'Central Coffee',
        'merchant_key' => 'central coffee',
    ]);
    $this->actingAs($owner);

    visit('/categories')
        ->click('[aria-label="Actions for Food"]')
        ->click('Archive')
        ->assertSee('Children to archive')
        ->assertSee('Dining')
        ->assertSee('Merchant Rules to disable')
        ->assertSee('Central Coffee (Food > Dining)')
        ->press('Cancel')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('an inline Category stays selected in the Review Queue', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'description' => 'City market',
    ]);
    $this->actingAs($owner);

    visit('/review-queue')
        ->click('@category-'.$transaction->id.'-trigger')
        ->click('@category-'.$transaction->id.'-create-option')
        ->fill(
            '#category-'.$transaction->id.'-new-name',
            'Market errands',
        )
        ->press('Create Category')
        ->assertSeeIn(
            '@category-'.$transaction->id.'-trigger',
            'Market errands',
        )
        ->press('Assign Category and continue')
        ->assertSee('Review Queue is clear')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($transaction->fresh())
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->category->name->toBe('Market errands');
});
