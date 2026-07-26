<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\SpendingNotificationReference;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\TransactionCorrection;
use App\Models\TransactionStateChange;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\TransactionVoidOperation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can combine ledger filters before the result limit is applied', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
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
            'category_assignment_provenance' => CategoryAssignmentProvenance::LinkedRefund,
        ]);
    $otherRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'merchant_description' => 'Neighborhood market duplicate',
            'original_purchase_id' => $purchase->id,
        ]);
    SuspectedDuplicate::factory()->for($owner, 'owner')->create([
        'first_transaction_id' => min($matchingRefund->id, $otherRefund->id),
        'second_transaction_id' => max($matchingRefund->id, $otherRefund->id),
    ]);

    Transaction::factory()
        ->count(101)
        ->for($owner, 'owner')
        ->create(['occurred_on' => '2026-07-24']);
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
            'duplicate_status' => 'suspected',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('filters.search', 'market refund')
            ->where('filters.review_state', 'outstanding')
            ->has('transactions', 1)
            ->where('transactions.0.id', $matchingRefund->id)
            ->where('transactions.0.review_state', 'outstanding')
            ->where('transactions.0.duplicate_status', 'suspected')
            ->has('voided_transactions', 0),
        );
});

test('ledger state filters distinguish every supported relationship state', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $clearPurchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->create([
            'occurred_on' => '2026-07-10',
            'merchant_description' => 'Clear purchase',
        ]);
    $categorizedPurchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->create([
            'occurred_on' => '2026-07-11',
            'merchant_description' => 'Categorized purchase',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $unlinkedReviewRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
            'occurred_on' => '2026-07-12',
            'merchant_description' => 'Unlinked review Refund',
        ]);
    $linkedRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->create([
            'occurred_on' => '2026-07-13',
            'merchant_description' => 'Linked Refund',
            'original_purchase_id' => $clearPurchase->id,
        ]);
    $voidedTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->create([
            'occurred_on' => '2026-07-14',
            'merchant_description' => 'Voided record',
            'voided_at' => now(),
        ]);
    [$firstSuspected, $secondSuspected] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->create(['occurred_on' => '2026-07-15']);
    SuspectedDuplicate::factory()->for($owner, 'owner')->create([
        'first_transaction_id' => min($firstSuspected->id, $secondSuspected->id),
        'second_transaction_id' => max($firstSuspected->id, $secondSuspected->id),
    ]);
    [$resolvedSurvivor, $resolvedVoided] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->create(['occurred_on' => '2026-07-16']);
    $resolvedVoided->update(['voided_at' => now()]);
    SuspectedDuplicate::factory()->for($owner, 'owner')->create([
        'first_transaction_id' => min($resolvedSurvivor->id, $resolvedVoided->id),
        'second_transaction_id' => max($resolvedSurvivor->id, $resolvedVoided->id),
        'revision' => 2,
        'survivor_transaction_id' => $resolvedSurvivor->id,
        'voided_transaction_id' => $resolvedVoided->id,
        'resolved_at' => now(),
    ]);
    $this->actingAs($owner);

    $this->get(route('transactions.index', ['category_state' => 'categorized']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $categorizedPurchase->id),
        );

    $this->get(route('transactions.index', ['category_state' => 'uncategorized']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions) => $transactions
                ->doesntContain('id', $categorizedPurchase->id)),
        );

    $this->get(route('transactions.index', ['review_state' => 'clear']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions) => $transactions
                ->contains('id', $clearPurchase->id)
                && $transactions->doesntContain('id', $unlinkedReviewRefund->id)
                && $transactions->doesntContain('id', $firstSuspected->id)),
        );

    $this->get(route('transactions.index', ['refund_relationship' => 'unlinked']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $unlinkedReviewRefund->id),
        );

    $this->get(route('transactions.index', ['refund_relationship' => 'linked']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $linkedRefund->id),
        );

    $this->get(route('transactions.index', ['refund_relationship' => 'not_applicable']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions) => $transactions
                ->every(fn (array $transaction): bool => $transaction['kind'] === 'purchase')),
        );

    $this->get(route('transactions.index', ['void_state' => 'voided']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 0)
            ->where('voided_transactions', fn (Collection $transactions) => $transactions
                ->contains('id', $voidedTransaction->id)),
        );

    $this->get(route('transactions.index', ['duplicate_status' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.0.id', $resolvedSurvivor->id)
            ->where('transactions.0.duplicate_status', 'resolved')
            ->where('voided_transactions.0.id', $resolvedVoided->id)
            ->where('voided_transactions.0.duplicate_status', 'resolved'),
        );

    $this->get(route('transactions.index', ['duplicate_status' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions', fn (Collection $transactions) => $transactions
                ->pluck('id')
                ->intersect([
                    $firstSuspected->id,
                    $secondSuspected->id,
                    $resolvedSurvivor->id,
                    $resolvedVoided->id,
                ])
                ->isEmpty()),
        );
});

test('unsupported ledger filters are rejected', function (string $field, string $value) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->get(route('transactions.index', [$field => $value]))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors($field);
})->with([
    'currency' => ['currency', 'EUR'],
    'Category state' => ['category_state', 'assigned'],
    'review state' => ['review_state', 'pending'],
    'Refund relationship' => ['refund_relationship', 'partial'],
    'void state' => ['void_state', 'deleted'],
    'duplicate status' => ['duplicate_status', 'merged'],
    'inspector state' => ['inspector', 'open'],
    'invalid date' => ['date_from', '2026-02-30'],
]);

test('selecting an owned Transaction returns its contextual inspector history and relationships', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->provisional([ReviewableTransactionField::OccurredOn])
        ->create([
            'revision' => 3,
            'occurred_on' => '2026-07-20',
            'amount_minor' => 2_500,
            'merchant_description' => 'Neighborhood market',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'original_purchase_id' => $transaction->id,
            'merchant_description' => 'Market Refund',
        ]);
    $similarTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 2_500,
            'merchant_description' => 'Market notification',
        ]);
    $suspectedDuplicate = SuspectedDuplicate::factory()->for($owner, 'owner')->create([
        'first_transaction_id' => min($transaction->id, $similarTransaction->id),
        'second_transaction_id' => max($transaction->id, $similarTransaction->id),
    ]);
    TransactionCorrection::factory()->for($transaction)->create([
        'field' => ReviewableTransactionField::MerchantDescription,
        'previous_value' => 'MARKET',
        'corrected_value' => 'Neighborhood market',
        'transaction_revision' => 2,
    ]);
    TransactionStateChange::factory()->for($owner, 'owner')->for($transaction)->create([
        'operation' => TransactionVoidOperation::Restore,
        'expected_revision' => 2,
        'result_revision' => 3,
        'result_voided_at' => null,
        'idempotency_key' => (string) Str::uuid(),
    ]);
    SpendingNotificationReference::factory()->for($owner, 'owner')->for($transaction)->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.id', $transaction->id)
            ->where('selected_transaction.category.name', 'Groceries')
            ->where('selected_transaction.category.provenance', 'owner')
            ->where('selected_transaction.review.fields.0.name', 'occurred_on')
            ->where('selected_transaction.corrections.0.corrected_value', 'Neighborhood market')
            ->where('selected_transaction.state_changes.0.operation', 'restore')
            ->where('selected_transaction.source_reference_count', 1)
            ->where('selected_transaction.linked_refunds.0.id', $refund->id)
            ->where('selected_transaction.duplicate_relationships.0.id', $suspectedDuplicate->id)
            ->where('selected_transaction.duplicate_relationships.0.status', 'suspected')
            ->where('selected_transaction.duplicate_relationships.0.other_transaction.id', $similarTransaction->id),
        );

});

test('the Review Queue is the outstanding preset of the ledger and shares its navigation count', function () {
    $owner = User::factory()->create();
    $reviewTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::MerchantDescription,
        ])
        ->create([
            'occurred_on' => '2026-07-20',
            'merchant_description' => 'Review target',
        ]);
    $similarTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-19',
        'merchant_description' => 'Filtered similar',
    ]);
    SuspectedDuplicate::factory()->for($owner, 'owner')->create([
        'first_transaction_id' => min($reviewTransaction->id, $similarTransaction->id),
        'second_transaction_id' => max($reviewTransaction->id, $similarTransaction->id),
    ]);
    Transaction::factory()->for($owner, 'owner')->create();
    $refundReviewPurchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->create(['occurred_on' => '2026-07-17']);
    Transaction::factory()->for($owner, 'owner')->refund()->create([
        'occurred_on' => '2026-07-18',
        'original_purchase_id' => $refundReviewPurchase->id,
        'refund_relationship_review_reasons' => [
            RefundRelationshipReviewReason::CumulativeRefundsExceedPurchase->value,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('review-queue/index')
            ->where('review_queue.outstanding_count', 4)
            ->where('workspace.mode', 'review_queue')
            ->where('filters.review_state', 'outstanding')
            ->has('workspace_transactions', 3)
            ->where('selected_transaction.id', $reviewTransaction->id),
        );

    $this->get(route('review_queue.index', ['search' => 'Filtered similar']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('workspace_transactions', 1)
            ->where('workspace_transactions.0.id', $similarTransaction->id)
            ->where('selected_transaction.id', $similarTransaction->id),
        );

    $this->get(route('review_queue.index', ['inspector' => 'closed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction', null),
        );
});
