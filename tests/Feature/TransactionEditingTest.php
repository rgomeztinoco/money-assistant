<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner directly edits the current Transaction and clears reviewed fields', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create(['merchant_description' => 'Original purchase']);
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->provisional([
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::AmountMinor,
            ReviewableTransactionField::Currency,
            ReviewableTransactionField::Kind,
            ReviewableTransactionField::MerchantDescription,
        ])
        ->create([
            'occurred_on' => '2026-07-01',
            'amount_minor' => 1_000,
            'merchant_description' => 'Imported merchant',
        ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $transaction), [
            'occurred_on' => '2026-08-10',
            'amount_minor' => 2_500,
            'currency' => 'PEN',
            'kind' => 'refund',
            'merchant_description' => 'Corrected merchant',
            'payment_instrument_label' => 'Visa',
            'payment_instrument_last_four' => '4242',
            'category_id' => $category->id,
            'original_purchase_id' => $purchase->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $transaction->refresh();

    expect($transaction->occurred_on->toDateString())->toBe('2026-08-10')
        ->and($transaction->amount_minor)->toBe(2_500)
        ->and($transaction->currency->value)->toBe('PEN')
        ->and($transaction->kind->value)->toBe('refund')
        ->and($transaction->merchant_description)->toBe('Corrected merchant')
        ->and($transaction->payment_instrument_label)->toBe('Visa')
        ->and($transaction->payment_instrument_last_four)->toBe('4242')
        ->and($transaction->category_id)->toBe($category->id)
        ->and($transaction->category_assignment_provenance)->toBe(CategoryAssignmentProvenance::Owner)
        ->and($transaction->original_purchase_id)->toBe($purchase->id)
        ->and($transaction->provisional_fields)->toBe([]);
});

test('the ledger is paginated and loads the selected inspector separately', function () {
    $owner = User::factory()->create();
    $transactions = Transaction::factory()
        ->count(26)
        ->for($owner, 'owner')
        ->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transactions->last()->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->has('transactions', 25)
            ->has('voided_transactions', 0)
            ->where('pagination.current_page', 1)
            ->where('pagination.per_page', 25)
            ->where('pagination.total', 26)
            ->missing('purchase_options')
            ->missing('selected_transaction')
            ->loadDeferredProps(fn (Assert $deferred) => $deferred
                ->where('selected_transaction.id', $transactions->last()->id)
                ->has('selected_transaction.purchase_options')),
        );

    $this->get(route('transactions.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('pagination.current_page', 2),
        );
});

test('editing a purchase recalculates its Refund review state from current amounts', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 10_000,
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 12_000,
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
            'original_purchase_id' => $purchase->id,
            'refund_relationship_review_reasons' => [
                RefundRelationshipReviewReason::CumulativeRefundsExceedPurchase->value,
            ],
        ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $purchase), [
            'occurred_on' => $purchase->occurred_on->toDateString(),
            'amount_minor' => 15_000,
            'currency' => 'USD',
            'kind' => 'purchase',
            'merchant_description' => $purchase->merchant_description,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->refund_relationship_review_reasons)->toBe([]);
});

test('editing one uncertain field preserves unrelated current review flags', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([
            ReviewableTransactionField::AmountMinor,
            ReviewableTransactionField::MerchantDescription,
        ])
        ->create([
            'amount_minor' => 1_000,
            'merchant_description' => 'Uncertain merchant',
        ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $transaction), [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 1_000,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => 'Correct merchant',
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->refresh()->provisional_fields)->toBe([
        ReviewableTransactionField::AmountMinor->value,
    ]);
});

test('an amount edit cannot silently invalidate an existing Receipt Breakdown', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create(['amount_minor' => 1_000]);
    $breakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    LineItem::factory()->for($breakdown)->create(['line_total_minor' => 1_000]);

    $this->actingAs($owner)
        ->from(route('transactions.index', ['selected' => $transaction->id]))
        ->put(route('transactions.update', $transaction), [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 1_200,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
        ])
        ->assertSessionHasErrors('amount_minor');

    expect($transaction->refresh()->amount_minor)->toBe(1_000)
        ->and($breakdown->lineItems()->count())->toBe(1);

    $this->put(route('transactions.update', $transaction), [
        'occurred_on' => $transaction->occurred_on->toDateString(),
        'amount_minor' => 1_200,
        'currency' => $transaction->currency->value,
        'kind' => $transaction->kind->value,
        'merchant_description' => $transaction->merchant_description,
        'remove_receipt_breakdown' => true,
    ])->assertSessionHasNoErrors();

    expect($transaction->refresh()->amount_minor)->toBe(1_200)
        ->and($transaction->receiptBreakdown()->exists())->toBeFalse();
});

test('a Transaction cannot be edited without the authenticated owner', function () {
    $transaction = Transaction::factory()->create();

    $this->put(route('transactions.update', $transaction), [
        'occurred_on' => '2026-08-10',
        'amount_minor' => 2_500,
        'currency' => 'PEN',
        'kind' => 'purchase',
        'merchant_description' => 'Not allowed',
    ])
        ->assertRedirect(route('login'));

    expect($transaction->refresh()->merchant_description)->not->toBe('Not allowed');
});
