<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner creates a child Category and assigns it from the Transaction inspector', function () {
    $owner = User::factory()->create();
    Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Neighborhood bistro',
    ]);
    $this->actingAs($owner);

    $page = visit('/categories');

    $page
        ->assertSee('Uncategorized remains a system state')
        ->fill('Name', 'Dining out')
        ->select('Parent', 'Food')
        ->fill('AI guidance description', 'Meals prepared by restaurants')
        ->fill('Example 1', 'Neighborhood bistro')
        ->press('Create Category')
        ->assertSee('Category created.')
        ->assertSee('Dining out')
        ->assertSee('Examples: Neighborhood bistro')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit('/transactions');

    $page
        ->press('Inspect')
        ->assertSee('Uncategorized')
        ->select('Assign Category', 'Food > Dining out')
        ->press('Save Category')
        ->assertSee('Category assigned.')
        ->assertSee('Dining out')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($transaction->fresh())
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->category->name->toBe('Dining out');
});
