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
    Category::factory()->create(['name' => 'Food']);
    $transaction = Transaction::factory()->create([
        'merchant_description' => 'Neighborhood bistro',
    ]);
    $this->actingAs($owner);

    $page = visit('/categories');

    $page
        ->assertSee('Uncategorized remains a system state')
        ->fill('Name', 'Dining out')
        ->select('Parent', 'Food')
        ->press('Create Category')
        ->assertSee('Category created.')
        ->assertSee('Dining out')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit('/transactions');

    $page
        ->press('Inspect')
        ->assertSee('Uncategorized')
        ->assertSee('Category needs review')
        ->select('Edit Category', 'Food > Dining out')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertSee('Dining out')
        ->assertSee('Assigned by '.$owner->name)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($transaction->fresh())
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->category->name->toBe('Dining out');
});
