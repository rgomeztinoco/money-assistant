<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can assign an active Category and return a Transaction to Uncategorized', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->merchant_rule_id->toBeNull();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.category.provenance.source', 'owner')
                ->where('selected_transaction.category.provenance.owner.id', $owner->id)
                ->where('selected_transaction.category.provenance.owner.name', $owner->name)
                ->missing('selected_transaction.category.provenance.linked_purchase')
                ->where('selected_transaction.category.provenance.merchant_rule', null)));

    $this->put(route('transactions.category.update', $transaction), [
        'category_id' => null,
    ])->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->merchant_rule_id->toBeNull();
});

test('an owner Category assignment replaces the current Merchant Rule source', function () {
    $owner = User::factory()->create();
    $ownerCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Owner choice']);
    $automatedCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Earlier choice']);
    $merchantRule = MerchantRule::factory()
        ->for($owner, 'owner')
        ->for($automatedCategory)
        ->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $automatedCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::MerchantRule,
        'merchant_rule_id' => $merchantRule->id,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'category_id' => $ownerCategory->id,
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBe($ownerCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->merchant_rule_id->toBeNull();
});

test('Category assignment rejects Retired Categories', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $retired = Category::factory()->for($owner, 'owner')->create(['retired_at' => now()]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'category_id' => $retired->id,
        ])->assertSessionHasErrors('category_id');

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('the Transaction workspace exposes active Category paths and not a customizable Uncategorized Category', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Groceries']);
    Category::factory()->for($owner, 'owner')->create(['name' => 'Old', 'retired_at' => now()]);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_options', [
                ['id' => $food->id, 'path' => 'Food'],
                ['id' => $food->children()->sole()->id, 'path' => 'Food > Groceries'],
            ])
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.category', null)));
});
