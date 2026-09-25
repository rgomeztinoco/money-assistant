<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Inertia\Testing\AssertableInertia as Assert;

test('amount bounds compare monetary magnitude in the selected currency', function () {
    $owner = User::factory()->create();
    $match = Transaction::factory()->for($owner, 'owner')->pen()->create([
        'description' => 'Signed purchase',
        'amount_minor' => -1250,
    ]);
    Transaction::factory()->for($owner, 'owner')->usd()->create([
        'description' => 'Other currency',
        'amount_minor' => 1250,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', [
            'currency' => 'PEN',
            'amount_min' => '12.50',
            'amount_max' => '12.50',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 1)
            ->where('transactions.0.id', $match->id));
});

test('the selected Transaction inspector exposes current state and relationships', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->provisional([ReviewableTransactionField::OccurredOn])
        ->create([
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->create([
        'original_spending_id' => $transaction->id,
    ]);
    SpendingNotificationReference::factory()->for($transaction)->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.id', $transaction->id)
                ->where('selected_transaction.category.name', 'Groceries')
                ->where('selected_transaction.category.provenance.source', 'owner')
                ->where('selected_transaction.review.fields.0.name', 'occurred_on')
                ->where('selected_transaction.source_reference_count', 1)
                ->where('selected_transaction.linked_refunds.0.id', $refund->id)
                ->missing('selected_transaction.spending_options')));
});

test('retired Transactions filters are rejected rather than silently ignored', function (string $field, string $value) {
    $this->actingAs(User::factory()->create())
        ->get(route('transactions.index', [$field => $value]))
        ->assertSessionHasErrors($field);
})->with([
    'category state' => ['category_state', 'archived'],
    'review state' => ['review_state', 'pending'],
    'Refund relationship' => ['refund_relationship', 'ambiguous'],
    'void state' => ['void_state', 'deleted'],
]);
