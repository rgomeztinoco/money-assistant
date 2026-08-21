<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can combine current-state ledger filters before pagination', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $purchase = Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-06-10',
        'merchant_description' => 'Original market purchase',
    ]);
    $matchingRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
            'occurred_on' => '2026-07-20',
            'merchant_description' => 'Neighborhood market Refund',
            'original_purchase_id' => $purchase->id,
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);

    Transaction::factory()->count(101)->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-24',
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', [
            'search' => 'market refund',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'currency' => 'USD',
            'category_state' => 'categorized',
            'review_state' => 'outstanding',
            'refund_relationship' => 'linked',
            'void_state' => 'active',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('filters.search', 'market refund')
            ->where('filters.review_state', 'outstanding')
            ->has('transactions', 1)
            ->where('transactions.0.id', $matchingRefund->id)
            ->where('transactions.0.review_state', 'outstanding')
            ->has('voided_transactions', 0));
});

test('ledger filters distinguish category, Refund relationship, review, and void state', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $clearPurchase = Transaction::factory()->for($owner, 'owner')->purchase()->create([
        'occurred_on' => '2026-07-10',
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $unlinkedReviewRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create(['occurred_on' => '2026-07-12']);
    $linkedRefund = Transaction::factory()->for($owner, 'owner')->refund()->create([
        'occurred_on' => '2026-07-13',
        'original_purchase_id' => $clearPurchase->id,
    ]);
    $voidedTransaction = Transaction::factory()->for($owner, 'owner')->purchase()->create([
        'occurred_on' => '2026-07-14',
        'voided_at' => now(),
    ]);

    $this->actingAs($owner);

    $this->get(route('transactions.index', ['category_state' => 'categorized']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions): bool => $transactions
                ->contains('id', $clearPurchase->id)
                && $transactions->doesntContain('id', $unlinkedReviewRefund->id)));

    $this->get(route('transactions.index', ['review_state' => 'clear']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions): bool => $transactions
                ->contains('id', $clearPurchase->id)
                && $transactions->doesntContain('id', $unlinkedReviewRefund->id)));

    $this->get(route('transactions.index', ['refund_relationship' => 'linked']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $linkedRefund->id));

    $this->get(route('transactions.index', ['refund_relationship' => 'unlinked']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $unlinkedReviewRefund->id));

    $this->get(route('transactions.index', ['void_state' => 'voided']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 0)
            ->where('voided_transactions', fn (Collection $transactions): bool => $transactions
                ->contains('id', $voidedTransaction->id)));
});

test('Category drill-down includes child Categories and Receipt Breakdown contributions', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    $shopping = Category::factory()->for($owner, 'owner')->create(['name' => 'Shopping']);
    $directFood = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $food->id,
    ]);
    $directDining = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $dining->id,
    ]);
    $itemized = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $shopping->id,
    ]);
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($itemized)->create();
    LineItem::factory()->for($breakdown)->create([
        'category_id' => $dining->id,
        'line_total_minor' => $itemized->amount_minor,
    ]);
    $unrelated = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $shopping->id,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', ['category_id' => $food->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions): bool => $transactions
                ->contains('id', $directFood->id)
                && $transactions->contains('id', $directDining->id)
                && $transactions->contains('id', $itemized->id)
                && $transactions->doesntContain('id', $unrelated->id)));

    $this->get(route('transactions.index', ['category_id' => $dining->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions): bool => $transactions
                ->doesntContain('id', $directFood->id)
                && $transactions->contains('id', $directDining->id)
                && $transactions->contains('id', $itemized->id)
                && $transactions->doesntContain('id', $unrelated->id)));
});

test('unsupported ledger filter values are rejected', function (string $field, string $value) {
    $this->actingAs(User::factory()->create())
        ->get(route('transactions.index', [$field => $value]))
        ->assertSessionHasErrors($field);
})->with([
    'category state' => ['category_state', 'archived'],
    'review state' => ['review_state', 'pending'],
    'Refund relationship' => ['refund_relationship', 'ambiguous'],
    'void state' => ['void_state', 'deleted'],
]);

test('the selected Transaction inspector exposes only current state and relationships', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->provisional([ReviewableTransactionField::OccurredOn])
        ->create([
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->create([
        'original_purchase_id' => $transaction->id,
    ]);
    SpendingNotificationReference::factory()->for($transaction)->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.id', $transaction->id)
                ->where('selected_transaction.category.name', 'Groceries')
                ->where('selected_transaction.category.provenance.source', 'owner')
                ->where('selected_transaction.review.fields.0.name', 'occurred_on')
                ->where('selected_transaction.source_reference_count', 1)
                ->where('selected_transaction.linked_refunds.0.id', $refund->id)));
});

test('the Review Queue is the outstanding ledger preset', function () {
    $owner = User::factory()->create();
    $reviewTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::MerchantDescription,
        ])
        ->create();
    $clearCategory = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $clearCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->create([
        'refund_relationship_review_reasons' => [
            RefundRelationshipReviewReason::CumulativeRefundsExceedPurchase->value,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('review_queue.index', ['selected' => $reviewTransaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('review-queue/index')
            ->where('workspace.mode', 'review_queue')
            ->where('filters.review_state', 'outstanding')
            ->has('transactions', 2)
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.id', $reviewTransaction->id)));
});
