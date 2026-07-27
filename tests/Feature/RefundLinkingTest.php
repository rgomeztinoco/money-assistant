<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

test('multiple partial Refunds link to one purchase without rewriting any Transaction', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 10_000,
        ]);
    $firstRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 3_000,
        ]);
    $secondRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-22',
            'amount_minor' => 2_000,
        ]);

    $this->actingAs($owner);

    foreach ([$firstRefund, $secondRefund] as $refund) {
        $this->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));
    }

    $this->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '5000')
            ->has('transactions', 3)
            ->where(
                'transactions.0.original_purchase.id',
                $purchase->id,
            )
            ->where(
                'transactions.1.original_purchase.id',
                $purchase->id,
            ),
        );

    expect(Transaction::query()->count())->toBe(3)
        ->and($purchase->refresh()->revision)->toBe(1)
        ->and($purchase->amount_minor)->toBe(10_000)
        ->and($firstRefund->refresh()->revision)->toBe(2)
        ->and($secondRefund->refresh()->revision)->toBe(2);
});

test('cumulative linked Refunds exceeding the purchase remain included and enter the Review Queue', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 10_000,
            'occurred_on' => '2026-07-20',
            'merchant_description' => 'Original purchase',
        ]);
    $firstRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 6_000,
            'occurred_on' => '2026-07-21',
            'merchant_description' => 'First partial Refund',
        ]);
    $excessiveRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 5_000,
            'occurred_on' => '2026-07-22',
            'merchant_description' => 'Second partial Refund',
        ]);

    $this->actingAs($owner);

    foreach ([$firstRefund, $excessiveRefund] as $refund) {
        $this->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '-1000')
            ->has('transactions', 3),
        );

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_refund_relationship_count', 1)
            ->has('refund_relationships', 1)
            ->where('refund_relationships.0.refund.id', $excessiveRefund->id)
            ->where('refund_relationships.0.purchase.id', $purchase->id)
            ->where(
                'refund_relationships.0.reason',
                'cumulative_refunds_exceed_purchase',
            )
            ->where('refund_relationships.0.linked_refund_total_minor', '11000')
            ->where('refund_relationships.0.overage_minor', '1000'),
        );
});

test('an unambiguous linked Refund defaults to the purchase Category and may make its total negative', function () {
    $owner = User::factory()->create();
    $category = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Clothing']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 10_000,
            'occurred_on' => '2026-07-20',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 15_000,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '-5000')
            ->where('transactions.0.category.id', $category->id)
            ->where('transactions.0.category.name', 'Clothing')
            ->where(
                'transactions.0.category.provenance.source',
                'linked_refund',
            )
            ->where('transactions.0.category.provenance.linked_purchase.id', $purchase->id)
            ->where('transactions.0.category.provenance.linked_purchase.merchant_description', $purchase->merchant_description)
            ->has('category_totals', 1)
            ->where('category_totals.0.category.id', $category->id)
            ->where('category_totals.0.totals.USD', '-5000')
            ->where('category_totals.0.totals.PEN', '0'),
        );
});

test('a linked Refund does not receive a Retired Category from its purchase', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Clothing',
        'retired_at' => now(),
    ]);
    $purchase = Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create();

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->fresh())
        ->original_purchase_id->toBe($purchase->id)
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull();
});

test('linked Refund Category precedence overrides automation', function (
    CategoryAssignmentProvenance $automationProvenance,
) {
    $owner = User::factory()->create();
    $purchaseCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Clothing']);
    $automatedCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Automated guess']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'category_id' => $purchaseCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'category_id' => $automatedCategory->id,
            'category_assignment_provenance' => $automationProvenance,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->category_id)->toBe($purchaseCategory->id)
        ->and($refund->category_assignment_provenance)
        ->toBe(CategoryAssignmentProvenance::LinkedRefund);
})->with([
    CategoryAssignmentProvenance::LearnedRule,
    CategoryAssignmentProvenance::Ai,
]);

test('a Refund linked to a purchase with a Receipt Breakdown stays Uncategorized for later allocation review', function () {
    $owner = User::factory()->create();
    $category = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Groceries']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 10_000,
            'occurred_on' => '2026-07-20',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($purchase)
        ->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 4_000,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '6000')
            ->where('transactions.0.category', null)
            ->where('category_totals.0.category.id', $category->id)
            ->where('category_totals.0.totals.USD', '10000'),
        );

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_refund_relationship_count', 1)
            ->has('refund_relationships', 1)
            ->where('refund_relationships.0.refund.id', $refund->id)
            ->where(
                'refund_relationships.0.reason',
                'receipt_breakdown_allocation_requires_review',
            ),
        );
});

test('an unlinked Refund keeps its independent owner Category', function () {
    $owner = User::factory()->create();
    $category = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Returns']);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->pen()
        ->create([
            'amount_minor' => 2_500,
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', '-2500')
            ->where('transactions.0.id', $refund->id)
            ->where('transactions.0.original_purchase', null)
            ->where('transactions.0.category.id', $category->id)
            ->where('transactions.0.category.provenance.source', 'owner')
            ->where('category_totals.0.totals.PEN', '-2500'),
        );
});

test('a stale Refund link is rejected without changing the current Transaction', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create(['revision' => 2]);

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('refund_link');

    expect($refund->refresh()->original_purchase_id)->toBeNull()
        ->and($refund->revision)->toBe(2);
});

test('invalid or unauthenticated Refund relationships are rejected', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create();

    $this->post(route('transactions.refund_link.store', $refund), [
        'purchase_id' => $purchase->id,
        'expected_revision' => 1,
    ])->assertRedirect(route('login'));

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => PHP_INT_MAX,
            'expected_revision' => 1,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('purchase_id');

    $this->from(route('transactions.index'))
        ->post(route('transactions.refund_link.store', $purchase), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('refund_link');

    expect($refund->refresh()->original_purchase_id)->toBeNull();
});

test('PostgreSQL prevents a Transaction from linking to itself', function () {
    $owner = User::factory()->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->create();

    expect(fn () => $refund->update([
        'original_purchase_id' => $refund->id,
    ]))->toThrow(QueryException::class);
});

test('PostgreSQL enforces a two-level Category hierarchy', function () {
    $owner = User::factory()->create();
    $parentCategory = Category::factory()
        ->for($owner, 'owner')
        ->create();
    $childCategory = Category::factory()
        ->for($owner, 'owner')
        ->for($parentCategory, 'parent')
        ->create();

    expect(fn () => Category::factory()
        ->for($owner, 'owner')
        ->for($childCategory, 'parent')
        ->create())->toThrow(QueryException::class);
});

test('cumulative Refund review remains exact beyond the PHP integer range', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => PHP_INT_MAX,
        ]);
    $fullRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => PHP_INT_MAX,
        ]);
    $excessiveRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-22',
            'amount_minor' => 1,
        ]);
    $this->actingAs($owner);

    foreach ([$fullRefund, $excessiveRefund] as $refund) {
        $this->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_refund_relationship_count', 1)
            ->where(
                'refund_relationships.0.linked_refund_total_minor',
                '9223372036854775808',
            )
            ->where('refund_relationships.0.overage_minor', '1'),
        );
});

test('Refunds cannot link across currencies', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->pen()
        ->create();

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('purchase_id');

    expect($refund->refresh()->original_purchase_id)->toBeNull();
});

test('the owner can choose an original purchase older than the visible 100-Transaction ledger', function () {
    $owner = User::factory()->create();
    $olderPurchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2025-01-01',
            'merchant_description' => 'Older original purchase',
        ]);
    Transaction::factory()
        ->count(100)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['occurred_on' => '2026-01-01']);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create(['occurred_on' => '2026-07-24']);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 100)
            ->where(
                'purchase_options',
                fn ($purchaseOptions): bool => $purchaseOptions
                    ->contains('id', $olderPurchase->id),
            ),
        );

    $this->post(route('transactions.refund_link.store', $refund), [
        'purchase_id' => $olderPurchase->id,
        'expected_revision' => 1,
    ])->assertSessionHasNoErrors();

    expect($refund->refresh()->original_purchase_id)->toBe($olderPurchase->id);
});

test('Receipt Breakdown review describes an existing owner Category accurately', function () {
    $owner = User::factory()->create();
    $purchaseCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Groceries']);
    $refundCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Returns']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'category_id' => $purchaseCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($purchase)
        ->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'category_id' => $refundCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'refund_relationships.0.refund.category_name',
                'Returns',
            ),
        );

    expect($refund->refresh()->category_id)->toBe($refundCategory->id)
        ->and($refund->category_assignment_provenance)->toBe(CategoryAssignmentProvenance::Owner);
});

test('second-level Category totals roll up to their current parent and remain exact when negative', function () {
    $owner = User::factory()->create();
    $parentCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Housing']);
    $childCategory = Category::factory()
        ->for($owner, 'owner')
        ->for($parentCategory, 'parent')
        ->create(['name' => 'Utilities']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 10_000,
            'category_id' => $childCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 15_000,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'purchase_id' => $purchase->id,
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_totals', function ($categoryTotals) use (
                $parentCategory,
                $childCategory,
            ): bool {
                $parentTotal = $categoryTotals->firstWhere(
                    'category.id',
                    $parentCategory->id,
                );
                $childTotal = $categoryTotals->firstWhere(
                    'category.id',
                    $childCategory->id,
                );

                return $parentTotal['totals']['USD'] === '-5000'
                    && $childTotal['totals']['USD'] === '-5000';
            }),
        );
});

test('a Receipt Breakdown does not replace Category totals before reconciled Line Items exist', function () {
    $owner = User::factory()->create();
    $category = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Groceries']);
    $purchase = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 1_000,
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($purchase)
        ->create();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_totals.0.category.id', $category->id)
            ->where('category_totals.0.totals.USD', '1000'),
        );
});
