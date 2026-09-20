<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
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
