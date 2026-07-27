<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can assign an active Category and return a Transaction to Uncategorized', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->revision->toBe(2);

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.category.provenance.source', 'owner')
            ->where('selected_transaction.category.provenance.owner.id', $owner->id)
            ->where('selected_transaction.category.provenance.owner.name', $owner->name)
            ->where('selected_transaction.category.provenance.linked_purchase', null)
            ->where('selected_transaction.category.provenance.learned_rule', null)
            ->where('selected_transaction.category.provenance.ai', null));

    $this->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 2,
        'category_id' => null,
    ])->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->revision->toBe(3);
});

test('an owner Category assignment replaces every lower-precedence source', function (
    ?CategoryAssignmentProvenance $currentProvenance,
) {
    $owner = User::factory()->create();
    $ownerCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Owner choice']);
    $automatedCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Earlier choice']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $currentProvenance === null ? null : $automatedCategory->id,
        'category_assignment_provenance' => $currentProvenance,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $ownerCategory->id,
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBe($ownerCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner);
})->with([
    'Uncategorized' => null,
    'linked Refund default' => CategoryAssignmentProvenance::LinkedRefund,
    'Learned Rule' => CategoryAssignmentProvenance::LearnedRule,
    'AI' => CategoryAssignmentProvenance::Ai,
]);

test('Learned Rule and AI assignments expose their exact provenance', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $ruleTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::LearnedRule,
    ]);
    $aiTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Ai,
    ]);

    CategoryAssignment::factory()
        ->for($owner, 'owner')
        ->for($ruleTransaction)
        ->for($category)
        ->create([
            'source' => CategoryAssignmentProvenance::LearnedRule->value,
            'transaction_revision' => 1,
            'learned_rule_id' => 42,
            'learned_rule_revision' => 3,
        ]);
    CategoryAssignment::factory()
        ->for($owner, 'owner')
        ->for($aiTransaction)
        ->for($category)
        ->create([
            'source' => CategoryAssignmentProvenance::Ai->value,
            'transaction_revision' => 1,
            'ai_classifier_version' => 'classifier-2026-07',
            'ai_confidence' => 87,
            'ai_outcome' => 'medium',
            'ai_explanation' => 'Merchant and guidance indicate groceries.',
        ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $ruleTransaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.category.provenance.source', 'learned_rule')
            ->where('selected_transaction.category.provenance.learned_rule.id', 42)
            ->where('selected_transaction.category.provenance.learned_rule.revision', 3));

    $this->get(route('transactions.index', ['selected' => $aiTransaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.category.provenance.source', 'ai')
            ->where('selected_transaction.category.provenance.ai.classifier_version', 'classifier-2026-07')
            ->where('selected_transaction.category.provenance.ai.confidence', 87)
            ->where('selected_transaction.category.provenance.ai.outcome', 'medium')
            ->where('selected_transaction.category.provenance.ai.explanation', 'Merchant and guidance indicate groceries.'));
});

test('Category assignment rejects stale revisions and Retired Categories', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create(['revision' => 2]);
    $retired = Category::factory()->for($owner, 'owner')->create(['retired_at' => now()]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => null,
        ])
        ->assertSessionHasErrors('expected_revision');

    $this->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 2,
        'category_id' => $retired->id,
    ])->assertSessionHasErrors('category_id');

    expect($transaction->fresh())
        ->category_id->toBeNull()
        ->revision->toBe(2);
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
            ->where('selected_transaction.category', null)
            ->where('category_options', [
                ['id' => $food->id, 'path' => 'Food'],
                ['id' => $food->children()->sole()->id, 'path' => 'Food > Groceries'],
            ]));
});
