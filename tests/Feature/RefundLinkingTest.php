<?php

use App\Actions\Breakdown\ReadBreakdown;
use App\Actions\Ledger\ReadReviewQueue;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{occurred_on: string, amount_minor: int, currency: string, kind: string, description: string, category_id: int|null, original_spending_id: int}
 */
function refundEditData(Transaction $refund, Transaction $spending): array
{
    return [
        'occurred_on' => $refund->occurred_on->toDateString(),
        'amount_minor' => $refund->amount_minor,
        'currency' => $refund->currency->value,
        'kind' => 'refund',
        'description' => $refund->description,
        'category_id' => $refund->category_id,
        'original_spending_id' => $spending->id,
    ];
}

function categoryTotalFor(User $owner, Currency $currency, int $categoryId): string
{
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

test('an existing Refund relationship survives a direct Transaction edit', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'description' => 'Original purchase',
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'description' => 'Refund',
        'original_spending_id' => $spending->id,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $refund), [
            'occurred_on' => $refund->occurred_on->toDateString(),
            'amount_minor' => $refund->amount_minor,
            'currency' => 'PEN',
            'kind' => 'refund',
            'description' => 'Corrected Refund',
            'original_spending_id' => $spending->id,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->original_spending_id)->toBe($spending->id);

    $this->get(route('transactions.index', ['selected' => $refund->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.original_spending.id', $spending->id)));
});

test('the retired Refund link endpoint cannot change an existing Transaction', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->create();

    $this->actingAs($owner)
        ->post('/transactions/'.$refund->id.'/refund-link', ['spending_id' => $spending->id])
        ->assertNotFound();

    expect($refund->refresh()->original_spending_id)->toBeNull();
});

test('linking a Refund through editing retains its own Category and Receipt Breakdown', function () {
    $owner = User::factory()->create();
    $spendingCategory = Category::factory()->for($owner, 'owner')->create();
    $refundCategory = Category::factory()->for($owner, 'owner')->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'category_id' => $spendingCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'category_id' => $refundCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $breakdown = ReceiptBreakdown::factory()->for($refund)->create();
    LineItem::factory()->for($breakdown)->create([
        'line_total_minor' => $refund->amount_minor,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $refund), refundEditData($refund, $spending))
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->original_spending_id)->toBe($spending->id)
        ->and($refund->category_id)->toBe($refundCategory->id)
        ->and($refund->category_assignment_provenance)->toBe(CategoryAssignmentProvenance::Owner)
        ->and($refund->receiptBreakdown?->is($breakdown))->toBeTrue();
});

test('linking a Refund cannot cross currencies', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create();

    $this->actingAs($owner)
        ->put(route('transactions.update', $refund), refundEditData($refund, $spending))
        ->assertSessionHasErrors('original_spending_id');

    expect($refund->refresh()->original_spending_id)->toBeNull();
});

test('linking a Refund does not copy an archived spending Category', function () {
    $owner = User::factory()->create();
    $archivedCategory = Category::factory()->for($owner, 'owner')->create([
        'archived_at' => now(),
    ]);
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'category_id' => $archivedCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'category_id' => null,
        'category_assignment_provenance' => null,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $refund), refundEditData($refund, $spending))
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->original_spending_id)->toBe($spending->id)
        ->and($refund->category_id)->toBeNull()
        ->and($refund->category_assignment_provenance)->toBeNull();
});

test('an unlinked Refund retains its independent owner Category', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'amount_minor' => 2_500,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.0.id', $refund->id)
            ->where('transactions.0.category.id', $category->id));

    expect($refund->refresh()->original_spending_id)->toBeNull()
        ->and($refund->category_assignment_provenance)->toBe(CategoryAssignmentProvenance::Owner)
        ->and(categoryTotalFor($owner, Currency::Pen, $category->id))->toBe('-2500');
});

test('cumulative linked Refund review stays exact beyond the PHP integer range', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'amount_minor' => PHP_INT_MAX,
    ]);
    $fullRefund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'amount_minor' => PHP_INT_MAX,
    ]);
    $extraRefund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'amount_minor' => 1,
    ]);
    $this->actingAs($owner);

    foreach ([$fullRefund, $extraRefund] as $refund) {
        $this->put(route('transactions.update', $refund), refundEditData($refund, $spending))
            ->assertSessionHasNoErrors();
    }

    $reviews = app(ReadReviewQueue::class)->handle($owner);
    $relationship = collect($reviews['refund_relationships'])
        ->firstWhere('refund.id', $extraRefund->id);

    expect($relationship['reason'])->toBe('cumulative_refunds_exceed_spending')
        ->and($relationship['linked_refund_total_minor'])->toBe('9223372036854775808')
        ->and($relationship['overage_minor'])->toBe('1');
});

test('a linked Refund with a Receipt Breakdown remains available for allocation review', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'amount_minor' => 10_000,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    ReceiptBreakdown::factory()->for($spending)->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'amount_minor' => 4_000,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $refund), refundEditData($refund, $spending))
        ->assertSessionHasNoErrors();

    $reviews = app(ReadReviewQueue::class)->handle($owner);

    expect($refund->refresh()->category_id)->toBeNull()
        ->and(collect($reviews['refund_relationships'])
            ->firstWhere('refund.id', $refund->id)['reason'])
        ->toBe('receipt_breakdown_allocation_requires_review')
        ->and(categoryTotalFor($owner, Currency::Usd, $category->id))->toBe((string) $spending->amount_minor);
});

test('second-level Category totals keep exact negative Refund amounts', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create();
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create();
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'amount_minor' => 10_000,
        'category_id' => $child->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'amount_minor' => 15_000,
        'category_id' => $child->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    expect(categoryTotalFor($owner, Currency::Usd, $parent->id))->toBe('-5000')
        ->and(categoryTotalFor($owner, Currency::Usd, $child->id))->toBe('-5000');
});

test('a Receipt Breakdown without reconciled Line Items keeps its Category total', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'amount_minor' => 1_000,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    ReceiptBreakdown::factory()->for($spending)->create();

    expect(categoryTotalFor($owner, Currency::Usd, $category->id))->toBe('1000');
});

test('Categories cannot be nested below a second level', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create();
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create();

    $this->actingAs($owner)
        ->from(route('categories.index'))
        ->post(route('categories.store'), [
            'name' => 'Third level',
            'parent_id' => $child->id,
        ])
        ->assertRedirect(route('categories.index'))
        ->assertSessionHasErrors('parent_id');

    expect(Category::query()->where('name', 'Third level')->doesntExist())->toBeTrue();
});
