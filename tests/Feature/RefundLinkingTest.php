<?php

use App\Actions\Breakdown\ReadBreakdown;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function categoryTotalFor(
    User $owner,
    Currency $currency,
    ?int $categoryId,
): string {
    $report = app(ReadBreakdown::class)->handle(
        owner: $owner,
        filters: [
            'currency' => $currency->value,
            'period' => 'custom',
            'date_from' => '2000-01-01',
            'date_to' => CarbonImmutable::today()->toDateString(),
        ],
    );

    return (string) collect($report['category_groups'])
        ->flatMap(fn (array $group): array => [$group, ...$group['children']])
        ->firstWhere('category.id', $categoryId)['amount_minor'][$currency->value];
}

test('multiple partial Refunds link to one spending without rewriting any Transaction', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
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
            'spending_id' => $spending->id,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));
    }

    $this->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->has('transactions', 3)
            ->where(
                'transactions.0.original_spending.id',
                $spending->id,
            )
            ->where(
                'transactions.1.original_spending.id',
                $spending->id,
            ),
        );

    expect(Transaction::query()->count())->toBe(3)
        ->and($spending->refresh()->amount_minor)->toBe(10_000)
        ->and($firstRefund->refresh()->original_spending_id)->toBe($spending->id)
        ->and($secondRefund->refresh()->original_spending_id)->toBe($spending->id);
});

test('cumulative linked Refunds exceeding the spending remain included and enter the Review Queue', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'amount_minor' => 10_000,
            'occurred_on' => '2026-07-20',
            'description' => 'Original spending',
        ]);
    $firstRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 6_000,
            'occurred_on' => '2026-07-21',
            'description' => 'First partial Refund',
        ]);
    $excessiveRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 5_000,
            'occurred_on' => '2026-07-22',
            'description' => 'Second partial Refund',
        ]);

    $this->actingAs($owner);

    foreach ([$firstRefund, $excessiveRefund] as $refund) {
        $this->post(route('transactions.refund_link.store', $refund), [
            'spending_id' => $spending->id,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->has('transactions', 3),
        );

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_refund_relationship_count', 1)
            ->has('refund_relationships', 1)
            ->where('refund_relationships.0.refund.id', $excessiveRefund->id)
            ->where('refund_relationships.0.spending.id', $spending->id)
            ->where(
                'refund_relationships.0.reason',
                'cumulative_refunds_exceed_spending',
            )
            ->where('refund_relationships.0.linked_refund_total_minor', '11000')
            ->where('refund_relationships.0.overage_minor', '1000'),
        );
});

test('a linked Refund does not receive an archived Category from its spending', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Clothing',
        'archived_at' => now(),
    ]);
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create();

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'spending_id' => $spending->id,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->fresh())
        ->original_spending_id->toBe($spending->id)
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull();
});

test('a linked Refund retains its own Category and Receipt Breakdown', function () {
    $owner = User::factory()->create();
    $spendingCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Clothing']);
    $refundCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Returns']);
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'category_id' => $spendingCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'category_id' => $refundCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $refundBreakdown = ReceiptBreakdown::factory()
        ->for($refund)
        ->create();

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'spending_id' => $spending->id,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->original_spending_id)->toBe($spending->id)
        ->and($refund->category_id)->toBe($refundCategory->id)
        ->and($refund->category_assignment_provenance)
        ->toBe(CategoryAssignmentProvenance::Owner)
        ->and($refund->receiptBreakdown?->is($refundBreakdown))->toBeTrue();
});

test('a Refund linked to a spending with a Receipt Breakdown stays Uncategorized for later allocation review', function () {
    $owner = User::factory()->create();
    $category = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Groceries']);
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'amount_minor' => 10_000,
            'occurred_on' => '2026-07-20',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    ReceiptBreakdown::factory()
        ->for($spending)
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
            'spending_id' => $spending->id,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->where('transactions.0.category', null)
            ->missing('category_totals'),
        );

    expect(categoryTotalFor($owner, Currency::Usd, $category->id))->toBe('10000');

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
            ->missing('totals')
            ->where('transactions.0.id', $refund->id)
            ->where('transactions.0.original_spending', null)
            ->where('transactions.0.category.id', $category->id)
            ->where('transactions.0.category.provenance.source', 'owner')
            ->missing('category_totals'),
        );

    expect(categoryTotalFor($owner, Currency::Pen, $category->id))->toBe('-2500');
});

test('invalid or unauthenticated Refund relationships are rejected', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create();

    $this->post(route('transactions.refund_link.store', $refund), [
        'spending_id' => $spending->id,
    ])->assertRedirect(route('login'));

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->post(route('transactions.refund_link.store', $refund), [
            'spending_id' => PHP_INT_MAX,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('spending_id');

    $this->from(route('transactions.index'))
        ->post(route('transactions.refund_link.store', $spending), [
            'spending_id' => $spending->id,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('refund_link');

    expect($refund->refresh()->original_spending_id)->toBeNull();
});

test('the Transaction workflow prevents a Refund from linking to itself', function () {
    $owner = User::factory()->create();
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->create();

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->put(route('transactions.update', $refund), [
            'occurred_on' => $refund->occurred_on->toDateString(),
            'amount_minor' => $refund->amount_minor,
            'currency' => $refund->currency->value,
            'kind' => $refund->kind->value,
            'description' => $refund->description,
            'original_spending_id' => $refund->id,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('original_spending_id');

    expect($refund->refresh()->original_spending_id)->toBeNull();
});

test('the Category workflow enforces a two-level hierarchy', function () {
    $owner = User::factory()->create();
    $parentCategory = Category::factory()
        ->for($owner, 'owner')
        ->create();
    $childCategory = Category::factory()
        ->for($owner, 'owner')
        ->for($parentCategory, 'parent')
        ->create();

    $this->actingAs($owner)
        ->from(route('categories.index'))
        ->post(route('categories.store'), [
            'name' => 'Third level',
            'parent_id' => $childCategory->id,
        ])
        ->assertRedirect(route('categories.index'))
        ->assertSessionHasErrors('parent_id');

    expect(Category::query()->where('name', 'Third level')->doesntExist())->toBeTrue();
});

test('cumulative Refund review remains exact beyond the PHP integer range', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
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
            'spending_id' => $spending->id,
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
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
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
            'spending_id' => $spending->id,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('spending_id');

    expect($refund->refresh()->original_spending_id)->toBeNull();
});

test('the owner can choose an original spending older than the visible Transaction page', function () {
    $owner = User::factory()->create();
    $olderSpending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'occurred_on' => '2025-01-01',
            'description' => 'Older original spending',
        ]);
    Transaction::factory()
        ->count(100)
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create(['occurred_on' => '2026-01-01']);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create(['occurred_on' => '2026-07-24']);

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $refund->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 25)
            ->where('pagination.total', 102)
            ->missing('spending_options')
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where(
                    'selected_transaction.spending_options',
                    fn ($spendingOptions): bool => $spendingOptions
                        ->contains('id', $olderSpending->id),
                )),
        );

    $this->post(route('transactions.refund_link.store', $refund), [
        'spending_id' => $olderSpending->id,
    ])->assertSessionHasNoErrors();

    expect($refund->refresh()->original_spending_id)->toBe($olderSpending->id);
});

test('Receipt Breakdown review describes an existing owner Category accurately', function () {
    $owner = User::factory()->create();
    $spendingCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Groceries']);
    $refundCategory = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Returns']);
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'category_id' => $spendingCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    ReceiptBreakdown::factory()
        ->for($spending)
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
            'spending_id' => $spending->id,
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
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
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
            'category_id' => $childCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund_link.store', $refund), [
            'spending_id' => $spending->id,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page->missing('category_totals'));

    expect(categoryTotalFor($owner, Currency::Usd, $parentCategory->id))->toBe('-5000')
        ->and(categoryTotalFor($owner, Currency::Usd, $childCategory->id))->toBe('-5000');
});

test('a Receipt Breakdown does not replace Category totals before reconciled Line Items exist', function () {
    $owner = User::factory()->create();
    $category = Category::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Groceries']);
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'amount_minor' => 1_000,
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    ReceiptBreakdown::factory()
        ->for($spending)
        ->create();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('category_totals'),
        );

    expect(categoryTotalFor($owner, Currency::Usd, $category->id))->toBe('1000');
});
