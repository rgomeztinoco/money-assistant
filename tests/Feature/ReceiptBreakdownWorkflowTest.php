<?php

use App\Actions\Reporting\ReadSpendingSummary;
use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

function receiptCategoryTotalFor(User $owner, ?int $categoryId): array
{
    return collect(app(ReadSpendingSummary::class)->handle($owner)['category_totals'])
        ->firstWhere('category.id', $categoryId);
}

test('the owner atomically saves a reconciled purchase Receipt Breakdown', function () {
    $owner = User::factory()->create();
    $fallbackCategory = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2_500,
        'category_id' => $fallbackCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.receipt_breakdowns.update', $transaction), [
            'line_items' => [
                [
                    'description' => 'Coffee beans',
                    'quantity' => '2',
                    'unit_price_minor' => 600,
                    'line_total_minor' => 1_200,
                    'category_id' => $groceries->id,
                ],
                [
                    'description' => 'Bread',
                    'quantity' => null,
                    'unit_price_minor' => null,
                    'line_total_minor' => 1_300,
                    'category_id' => null,
                ],
            ],
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('category_totals')
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.receipt_breakdown.total_minor', '2500')
                ->has('selected_transaction.receipt_breakdown.line_items', 2)
                ->where('selected_transaction.receipt_breakdown.line_items.0.description', 'Coffee beans')
                ->where('selected_transaction.receipt_breakdown.line_items.0.quantity', '2')
                ->where('selected_transaction.receipt_breakdown.line_items.0.unit_price_minor', '600')
                ->where('selected_transaction.receipt_breakdown.line_items.0.category.name', 'Groceries')
                ->where('selected_transaction.receipt_breakdown.line_items.1.category', null)));

    expect(receiptCategoryTotalFor($owner, $groceries->id)['totals']['PEN'])->toBe('1200')
        ->and(receiptCategoryTotalFor($owner, null)['totals']['PEN'])->toBe('1300');
});

test('an unreconciled replacement leaves the current Receipt Breakdown unchanged', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2_500,
    ]);
    $this->actingAs($owner);

    saveReceiptBreakdown($this, $transaction, [[
        'description' => 'Original itemization',
        'quantity' => null,
        'unit_price_minor' => null,
        'line_total_minor' => 2_500,
        'category_id' => null,
    ]])->assertSessionHasNoErrors();

    saveReceiptBreakdown($this, $transaction, [[
        'description' => 'Incomplete replacement',
        'quantity' => null,
        'unit_price_minor' => null,
        'line_total_minor' => 2_400,
        'category_id' => null,
    ]])->assertSessionHasErrors('line_items');

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->has('selected_transaction.receipt_breakdown.line_items', 1)
                ->where('selected_transaction.receipt_breakdown.line_items.0.description', 'Original itemization')
                ->where('selected_transaction.receipt_breakdown.total_minor', '2500')));
});

test('saving again replaces every Line Item and removal restores Transaction Category reporting', function () {
    $owner = User::factory()->create();
    $fallbackCategory = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $dining = Category::factory()->recycle($owner)->create(['name' => 'Dining']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2_500,
        'category_id' => $fallbackCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $this->actingAs($owner);

    saveReceiptBreakdown($this, $transaction, [[
        'description' => 'Groceries',
        'quantity' => null,
        'unit_price_minor' => null,
        'line_total_minor' => 2_500,
        'category_id' => $groceries->id,
    ]])->assertSessionHasNoErrors();

    saveReceiptBreakdown($this, $transaction, [[
        'description' => 'Lunch',
        'quantity' => '1',
        'unit_price_minor' => 2_500,
        'line_total_minor' => 2_700,
        'category_id' => $dining->id,
    ], [
        'description' => 'Discount',
        'quantity' => null,
        'unit_price_minor' => null,
        'line_total_minor' => -200,
        'category_id' => $dining->id,
    ]])->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->has('selected_transaction.receipt_breakdown.line_items', 2)
                ->where('selected_transaction.receipt_breakdown.line_items.0.description', 'Lunch')
                ->where('selected_transaction.receipt_breakdown.line_items.1.description', 'Discount')));

    expect(receiptCategoryTotalFor($owner, $dining->id)['totals']['PEN'])->toBe('2500');

    $this->delete(route('transactions.receipt_breakdowns.destroy', $transaction))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.receipt_breakdown', null)));

    expect(receiptCategoryTotalFor($owner, $fallbackCategory->id)['totals']['PEN'])->toBe('2500');
});

test('the owner saves an independently reviewed Refund Receipt Breakdown', function () {
    $owner = User::factory()->create();
    $purchaseCategory = Category::factory()->recycle($owner)->create(['name' => 'Appliances']);
    $refundCategory = Category::factory()->recycle($owner)->create(['name' => 'Returns']);
    $purchase = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2_500,
        'category_id' => $purchaseCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $refund = Transaction::factory()->recycle($owner)->refund()->pen()->create([
        'amount_minor' => 800,
        'original_purchase_id' => $purchase->id,
    ]);
    $this->actingAs($owner);

    saveReceiptBreakdown($this, $refund, [[
        'description' => 'Returned appliance part',
        'line_total_minor' => 800,
        'category_id' => $refundCategory->id,
    ]])->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $refund->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.kind', 'refund')
                ->where('selected_transaction.receipt_breakdown.line_items.0.description', 'Returned appliance part')));

    expect(receiptCategoryTotalFor($owner, $purchaseCategory->id)['totals']['PEN'])->toBe('2500')
        ->and(receiptCategoryTotalFor($owner, $refundCategory->id)['totals']['PEN'])->toBe('-800');
});

test('Uncategorized Line Items remain visible in the Review Queue', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2_500,
    ]);
    $this->actingAs($owner);

    saveReceiptBreakdown($this, $transaction, [[
        'description' => 'Known item',
        'quantity' => null,
        'unit_price_minor' => null,
        'line_total_minor' => 2_300,
        'category_id' => Category::factory()->recycle($owner)->create()->id,
    ], [
        'description' => 'Receipt detail unavailable',
        'quantity' => null,
        'unit_price_minor' => null,
        'line_total_minor' => 200,
        'category_id' => null,
    ]])->assertSessionHasNoErrors();

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->where('review_queue.outstanding_count', 1)
            ->where('transactions.0.id', $transaction->id));
});

test('Receipt Breakdown persistence contains no lifecycle or adjustment-role state', function () {
    foreach ([
        'status',
        'revision',
        'confirmed_at',
        'deletion_id',
        'purge_after',
        'deleted_at',
    ] as $column) {
        expect(Schema::hasColumn('receipt_breakdowns', $column))->toBeFalse();
    }

    foreach ([
        'receipt_breakdown_revision',
        'receipt_breakdown_status',
    ] as $column) {
        expect(Schema::hasColumn('suspected_duplicate_receipt_breakdown_moves', $column))->toBeFalse();
    }

    foreach ([
        'role',
        'related_line_item_id',
        'requires_review',
    ] as $column) {
        expect(Schema::hasColumn('line_items', $column))->toBeFalse();
    }

    expect(ReceiptBreakdown::query()->count())->toBe(0);
});

/**
 * @param  list<array{description: string, quantity?: string|null, unit_price_minor?: int|null, line_total_minor: int, category_id?: int|null}>  $lineItems
 */
function saveReceiptBreakdown(
    TestCase $test,
    Transaction $transaction,
    array $lineItems,
): TestResponse {
    return $test->put(route('transactions.receipt_breakdowns.update', $transaction), [
        'line_items' => $lineItems,
    ]);
}
