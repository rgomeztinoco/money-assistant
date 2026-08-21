<?php

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

function receiptCategoryTotalFor(
    TestCase $test,
    User $owner,
    Currency $currency,
    ?int $categoryId,
): string {
    $response = $test->actingAs($owner)->get(route('reports.show', [
        'currency' => $currency,
        'date_from' => '2000-01-01',
        'date_to' => now()->toDateString(),
    ]));

    return (string) collect($response->inertiaProps('category_groups'))
        ->flatMap(fn (array $group): array => [$group, ...$group['children']])
        ->firstWhere('category.id', $categoryId)['amount_minor'];
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

    expect(receiptCategoryTotalFor($this, $owner, Currency::Pen, $groceries->id))->toBe('1200')
        ->and(receiptCategoryTotalFor($this, $owner, Currency::Pen, null))->toBe('1300');
});

test('the owner records signed Line Item totals in currency units', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->usd()->create([
        'amount_minor' => 2_500,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.receipt_breakdowns.update', $transaction), [
            'line_items' => [
                [
                    'description' => 'Lunch',
                    'quantity' => '1',
                    'unit_price' => '27.00',
                    'line_total' => '27.00',
                    'category_id' => null,
                ],
                [
                    'description' => 'Discount',
                    'quantity' => null,
                    'unit_price' => null,
                    'line_total' => '-2.00',
                    'category_id' => null,
                ],
            ],
        ])
        ->assertSessionHasNoErrors();

    $lineItems = $transaction->receiptBreakdown()->firstOrFail()->lineItems;

    expect($lineItems)->toHaveCount(2)
        ->and($lineItems[0]->unit_price_minor)->toBe(2_700)
        ->and($lineItems[0]->line_total_minor)->toBe(2_700)
        ->and($lineItems[1]->line_total_minor)->toBe(-200);
});

test('Receipt Breakdown currency-unit totals reject fractional minor units', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->usd()->create([
        'amount_minor' => 2_500,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.receipt_breakdowns.update', $transaction), [
            'line_items' => [[
                'description' => 'Inexact total',
                'line_total' => '25.001',
                'category_id' => null,
            ]],
        ])
        ->assertSessionHasErrors('line_items.0.line_total');

    expect($transaction->receiptBreakdown()->doesntExist())->toBeTrue();
});

test('Receipt Breakdown input cannot mix currency and minor units', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->usd()->create([
        'amount_minor' => 2_500,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.receipt_breakdowns.update', $transaction), [
            'line_items' => [[
                'description' => 'Ambiguous item',
                'unit_price' => '25.00',
                'unit_price_minor' => 2_500,
                'line_total' => '25.00',
                'line_total_minor' => 2_500,
                'category_id' => null,
            ]],
        ])
        ->assertSessionHasErrors([
            'line_items.0.unit_price',
            'line_items.0.unit_price_minor',
            'line_items.0.line_total',
            'line_items.0.line_total_minor',
        ]);

    expect($transaction->receiptBreakdown()->doesntExist())->toBeTrue();
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
    ]])->assertSessionHasErrors([
        'line_items' => 'Line Item totals must reconcile exactly. 1.00 PEN remaining.',
    ]);

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

    expect(receiptCategoryTotalFor($this, $owner, Currency::Pen, $dining->id))->toBe('2500');

    $this->delete(route('transactions.receipt_breakdowns.destroy', $transaction))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.receipt_breakdown', null)));

    expect(receiptCategoryTotalFor($this, $owner, Currency::Pen, $fallbackCategory->id))->toBe('2500');
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

    expect(receiptCategoryTotalFor($this, $owner, Currency::Pen, $purchaseCategory->id))->toBe('2500')
        ->and(receiptCategoryTotalFor($this, $owner, Currency::Pen, $refundCategory->id))->toBe('-800');
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
            ->where('transactions.0.id', $transaction->id));
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
