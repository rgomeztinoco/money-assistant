<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\DailyExchangeRate;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\ReceiptProposal;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Inertia\Testing\AssertableInertia as Assert;

test('a Receipt Proposal remains unattached until the owner selects a Transaction', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $proposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 2200,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $transaction->merchant_description,
        ],
        'proposed_line_items' => [
            ['description' => 'Coffee', 'line_total_minor' => 1000],
            ['description' => 'Fruit', 'line_total_minor' => 1200],
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.receipt_breakdown.draft', null)
            ->has('selected_transaction.receipt_proposals', 1)
            ->where('selected_transaction.receipt_proposals.0.id', $proposal->proposal_id)
            ->where('category_totals.0.category.name', 'Groceries')
            ->where('category_totals.0.totals.PEN', '2500'));

    expect(ReceiptBreakdown::query()->count())->toBe(0);

    $this->post(route('transactions.receipt_proposal_attachments.store', $transaction), [
        'receipt_proposal_id' => $proposal->proposal_id,
    ])->assertRedirect(route('transactions.index'));

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.receipt_breakdown.draft.revision', 1)
            ->where('selected_transaction.receipt_breakdown.draft.total_minor', '2200')
            ->where('selected_transaction.receipt_breakdown.draft.delta_minor', '300')
            ->has('selected_transaction.receipt_breakdown.draft.line_items', 2)
            ->where('selected_transaction.receipt_proposals', [])
            ->where('category_totals.0.category.name', 'Groceries')
            ->where('category_totals.0.totals.PEN', '2500'));

    $secondProposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => $proposal->proposed_transaction,
    ]);

    $this->post(route('transactions.receipt_proposal_attachments.store', $transaction), [
        'receipt_proposal_id' => $secondProposal->proposal_id,
    ])->assertSessionHasErrors('receipt_proposal_id');

    expect(ReceiptBreakdown::query()->where('status', 'draft')->count())->toBe(1);
});

test('a Receipt Proposal preserves signed adjustments and printed item context', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2600,
    ]);
    $proposal = ReceiptProposal::factory()->recycle($owner)->create([
        'contract_version' => 2,
        'proposed_transaction' => [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 2600,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $transaction->merchant_description,
        ],
        'proposed_line_items' => [
            [
                'description' => 'Coffee beans',
                'role' => 'purchased_item',
                'quantity' => '3',
                'unit_price_minor' => 999,
                'line_total_minor' => 2500,
            ],
            [
                'description' => 'Tax',
                'role' => 'tax',
                'quantity' => null,
                'unit_price_minor' => null,
                'line_total_minor' => 300,
            ],
            [
                'description' => 'Printed discount',
                'role' => 'discount',
                'quantity' => null,
                'unit_price_minor' => null,
                'line_total_minor' => -200,
            ],
        ],
    ]);

    $this->actingAs($owner)
        ->post(route('transactions.receipt_proposal_attachments.store', $transaction), [
            'receipt_proposal_id' => $proposal->proposal_id,
        ])
        ->assertRedirect();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.receipt_breakdown.draft.total_minor', '2600')
            ->where('selected_transaction.receipt_breakdown.draft.delta_minor', '0')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.0.role', 'purchased_item')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.0.quantity', '3')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.0.unit_price_minor', '999')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.0.line_total_minor', '2500')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.1.role', 'tax')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.1.line_total_minor', '300')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.2.role', 'discount')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.2.line_total_minor', '-200'));
});

test('the signed receipt migration refuses a destructive rollback with extended receipt data', function () {
    $owner = User::factory()->create();
    ReceiptProposal::factory()->recycle($owner)->create([
        'contract_version' => 2,
    ]);

    $migration = require database_path('migrations/2026_07_28_200908_extend_receipt_line_items_for_adjustments.php');

    expect(fn () => $migration->down())->toThrow(
        LogicException::class,
        'Cannot roll back signed receipt adjustments',
    );

    $constraint = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conname = 'receipt_proposals_contract_version_supported'
        SQL);

    expect($constraint?->definition)->toContain('contract_version = ANY')
        ->toContain('2');
});

test('the owner edits only the expected draft revision and drafts remain outside reports', function () {
    $owner = User::factory()->create();
    $transactionCategory = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $coffeeCategory = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
        'category_id' => $transactionCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $proposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 2500,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $transaction->merchant_description,
        ],
        'proposed_line_items' => [
            ['description' => 'Coffee', 'line_total_minor' => 1000],
            ['description' => 'Fruit', 'line_total_minor' => 1200],
        ],
    ]);

    $this->actingAs($owner)->post(
        route('transactions.receipt_proposal_attachments.store', $transaction),
        ['receipt_proposal_id' => $proposal->proposal_id],
    )->assertRedirect();

    $draft = ReceiptBreakdown::query()->with('lineItems')->sole();
    $lineItems = $draft->lineItems->values();
    $replacement = [
        [
            'id' => $lineItems[0]->line_item_id,
            'description' => 'Coffee beans',
            'line_total_minor' => 1200,
            'category_id' => $coffeeCategory->id,
        ],
        [
            'id' => null,
            'description' => 'Bread',
            'line_total_minor' => 1300,
            'category_id' => null,
        ],
    ];

    $unsafeReplacement = $replacement;
    $unsafeReplacement[0]['line_total_minor'] = 9_007_199_254_740_992;

    $this->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => $unsafeReplacement,
    ])->assertSessionHasErrors('line_items.0.line_total_minor');

    expect($draft->refresh()->revision)->toBe(1);

    $this->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => $replacement,
    ])->assertRedirect();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.receipt_breakdown.draft.revision', 2)
            ->where('selected_transaction.receipt_breakdown.draft.total_minor', '2500')
            ->where('selected_transaction.receipt_breakdown.draft.delta_minor', '0')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.0.category.name', 'Groceries')
            ->where('selected_transaction.receipt_breakdown.draft.line_items.1.description', 'Bread')
            ->where('category_totals.0.category.name', 'Shopping')
            ->where('category_totals.0.totals.PEN', '2500'));

    $replacement[0]['line_total_minor'] = 1100;

    $this->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => $replacement,
    ])->assertSessionHasErrors('expected_revision');

    expect($draft->refresh()->revision)->toBe(2)
        ->and($draft->lineItems()->firstOrFail()->line_item_id)->toBe($lineItems[0]->line_item_id)
        ->and($draft->lineItems()->where('line_item_id', $lineItems[1]->line_item_id)->exists())->toBeFalse()
        ->and($draft->lineItems()->count())->toBe(2);
});

test('the owner confirms an explicit Uncategorized Unidentified Line Item for a known amount', function () {
    $owner = User::factory()->create();
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $fees = Category::factory()->recycle($owner)->create(['name' => 'Fees & Taxes']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2600,
    ]);
    $proposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 2600,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $transaction->merchant_description,
        ],
        'proposed_line_items' => [[
            'description' => 'Incomplete receipt',
            'line_total_minor' => 2600,
        ]],
    ]);

    $this->actingAs($owner)->post(
        route('transactions.receipt_proposal_attachments.store', $transaction),
        ['receipt_proposal_id' => $proposal->proposal_id],
    )->assertRedirect();

    $draft = ReceiptBreakdown::query()->sole();

    $this->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => [
            [
                'id' => null,
                'description' => 'Coffee beans',
                'role' => 'purchased_item',
                'quantity' => '2',
                'unit_price_minor' => 1250,
                'line_total_minor' => 2500,
                'category_id' => $groceries->id,
            ],
            [
                'id' => null,
                'description' => 'Printed discount',
                'role' => 'discount',
                'quantity' => null,
                'unit_price_minor' => null,
                'line_total_minor' => -200,
                'category_id' => $groceries->id,
            ],
            [
                'id' => null,
                'description' => 'Service fee',
                'role' => 'fee',
                'quantity' => null,
                'unit_price_minor' => null,
                'line_total_minor' => 100,
                'category_id' => $fees->id,
            ],
            [
                'id' => null,
                'description' => 'Receipt detail unavailable',
                'role' => 'unidentified',
                'quantity' => null,
                'unit_price_minor' => null,
                'line_total_minor' => 200,
                'category_id' => null,
            ],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('receipt_breakdowns.confirmation.store', $draft), [
        'expected_revision' => 2,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.receipt_breakdown.confirmed.total_minor', '2600')
            ->where('selected_transaction.receipt_breakdown.confirmed.line_items.3.role', 'unidentified')
            ->where('selected_transaction.receipt_breakdown.confirmed.line_items.3.category', null)
            ->where('selected_transaction.receipt_breakdown.confirmed.line_items.3.requires_review', true)
            ->where('category_totals.0.category.name', 'Fees & Taxes')
            ->where('category_totals.0.totals.PEN', '100')
            ->where('category_totals.1.category.name', 'Groceries')
            ->where('category_totals.1.totals.PEN', '2300')
            ->where('category_totals.2.category.name', 'Uncategorized')
            ->where('category_totals.2.totals.PEN', '200'));

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->where('review_queue.outstanding_count', 1));

    expect($draft->refresh()->lineItems()->count())->toBe(4);
});

test('an item-specific adjustment defaults to its purchased item Category', function () {
    $owner = User::factory()->create();
    $groceries = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2600,
    ]);
    $draft = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->draft()->create();
    $purchasedItem = LineItem::factory()->for($draft)->create([
        'description' => 'Coffee beans',
        'line_total_minor' => 2500,
        'category_id' => $groceries->id,
    ]);
    $tax = LineItem::factory()->for($draft)->create([
        'description' => 'Item tax',
        'role' => 'tax',
        'line_total_minor' => 100,
        'category_id' => null,
    ]);

    $this->actingAs($owner)
        ->post(route('receipt_breakdowns.confirmation.store', $draft), [
            'expected_revision' => 1,
        ])
        ->assertSessionHasErrors('line_items');

    $this->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => [
            [
                'id' => $purchasedItem->line_item_id,
                'description' => $purchasedItem->description,
                'line_total_minor' => 2500,
                'category_id' => $groceries->id,
            ],
            [
                'id' => $tax->line_item_id,
                'description' => $tax->description,
                'role' => 'tax',
                'line_total_minor' => 100,
                'category_id' => null,
                'related_line_item_id' => $purchasedItem->line_item_id,
            ],
        ],
    ])->assertSessionHasNoErrors();

    $this->post(route('receipt_breakdowns.confirmation.store', $draft), [
        'expected_revision' => 2,
    ])->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.receipt_breakdown.confirmed.line_items.1.related_line_item_id', $purchasedItem->line_item_id)
            ->where('selected_transaction.receipt_breakdown.confirmed.line_items.1.category.name', 'Groceries')
            ->where('category_totals.0.category.name', 'Groceries')
            ->where('category_totals.0.totals.PEN', '2600'));
});

test('only an exactly reconciled expected draft confirms and replaces Transaction Category reporting', function () {
    $owner = User::factory()->create();
    $transactionCategory = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $lineItemCategory = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
        'category_id' => $transactionCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $proposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => 2200,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $transaction->merchant_description,
        ],
        'proposed_line_items' => [
            ['description' => 'Coffee', 'line_total_minor' => 1000],
            ['description' => 'Fruit', 'line_total_minor' => 1200],
        ],
    ]);

    $this->actingAs($owner)->post(
        route('transactions.receipt_proposal_attachments.store', $transaction),
        ['receipt_proposal_id' => $proposal->proposal_id],
    )->assertRedirect();

    $draft = ReceiptBreakdown::query()->with('lineItems')->sole();

    $this->post(route('receipt_breakdowns.confirmation.store', $draft), [
        'expected_revision' => 1,
    ])->assertSessionHasErrors('reconciliation');

    expect($draft->lineItems()->count())->toBe(2);

    $lineItems = $draft->lineItems->values();

    $this->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => [
            [
                'id' => $lineItems[0]->line_item_id,
                'description' => 'Coffee',
                'line_total_minor' => 1200,
                'category_id' => $lineItemCategory->id,
            ],
            [
                'id' => $lineItems[1]->line_item_id,
                'description' => 'Fruit',
                'line_total_minor' => 1300,
                'category_id' => null,
            ],
        ],
    ])->assertRedirect();

    $this->post(route('receipt_breakdowns.confirmation.store', $draft), [
        'expected_revision' => 1,
    ])->assertSessionHasErrors('expected_revision');

    $this->post(route('receipt_breakdowns.confirmation.store', $draft), [
        'expected_revision' => 2,
    ])->assertRedirect();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', '2500')
            ->where('selected_transaction.receipt_breakdown.draft', null)
            ->where('selected_transaction.receipt_breakdown.confirmed.revision', 2)
            ->where('selected_transaction.receipt_breakdown.confirmed.total_minor', '2500')
            ->has('category_totals', 2)
            ->where('category_totals.0.category.name', 'Groceries')
            ->where('category_totals.0.totals.PEN', '1200')
            ->where('category_totals.1.category.name', 'Uncategorized')
            ->where('category_totals.1.totals.PEN', '1300'));

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->where('review_queue.outstanding_count', 1)
            ->where('workspace_transactions.0.id', $transaction->id));

    $replacementProposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => $proposal->proposed_transaction,
        'proposed_line_items' => [[
            'description' => 'Complete replacement',
            'line_total_minor' => 2500,
        ]],
    ]);

    $this->post(route('transactions.receipt_proposal_attachments.store', $transaction), [
        'receipt_proposal_id' => $replacementProposal->proposal_id,
    ])->assertRedirect();

    $replacementDraft = ReceiptBreakdown::query()->where('status', 'draft')->sole();

    $this->post(route('receipt_breakdowns.confirmation.store', $replacementDraft), [
        'expected_revision' => 1,
    ])->assertRedirect();

    expect($draft->refresh()->status)->toBe('superseded')
        ->and($draft->lineItems()->count())->toBe(2)
        ->and(ReceiptBreakdown::query()->where('status', 'draft')->count())->toBe(0)
        ->and(ReceiptBreakdown::query()->where('status', 'confirmed')->count())->toBe(1)
        ->and(ReceiptBreakdown::query()->count())->toBe(2);
});

test('a linked Refund receives an independently reviewed Receipt Breakdown', function () {
    $owner = User::factory()->create();
    $purchaseCategory = Category::factory()->recycle($owner)->create(['name' => 'Appliances']);
    $refundCategory = Category::factory()->recycle($owner)->create(['name' => 'Returns']);
    $purchase = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
    ]);
    $purchaseBreakdown = ReceiptBreakdown::factory()->recycle($owner)->for($purchase)->create();
    LineItem::factory()->for($purchaseBreakdown)->create([
        'description' => 'Coffee maker',
        'category_id' => $purchaseCategory->id,
        'line_total_minor' => 2500,
    ]);
    $refund = Transaction::factory()->recycle($owner)->refund()->pen()->create([
        'amount_minor' => 800,
        'original_purchase_id' => $purchase->id,
    ]);
    $proposal = ReceiptProposal::factory()->recycle($owner)->create([
        'proposed_transaction' => [
            'occurred_on' => $refund->occurred_on->toDateString(),
            'amount_minor' => 800,
            'currency' => 'PEN',
            'kind' => 'refund',
            'merchant_description' => $refund->merchant_description,
        ],
        'proposed_line_items' => [[
            'description' => 'Independently reviewed return',
            'line_total_minor' => 800,
        ]],
    ]);

    $this->actingAs($owner)->post(
        route('transactions.receipt_proposal_attachments.store', $refund),
        ['receipt_proposal_id' => $proposal->proposal_id],
    )->assertRedirect()->assertSessionHasNoErrors();

    $refundDraft = ReceiptBreakdown::query()
        ->where('transaction_id', $refund->id)
        ->where('status', 'draft')
        ->sole();
    $refundLineItem = $refundDraft->lineItems()->sole();

    expect($refundLineItem->description)->toBe('Independently reviewed return')
        ->and($purchaseBreakdown->lineItems()->sole()->description)->toBe('Coffee maker')
        ->and($refundDraft->lineItems()->count())->toBe(1);

    $this->put(route('receipt_breakdowns.update', $refundDraft), [
        'expected_revision' => 1,
        'line_items' => [[
            'id' => $refundLineItem->line_item_id,
            'description' => $refundLineItem->description,
            'line_total_minor' => 800,
            'category_id' => $refundCategory->id,
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('receipt_breakdowns.confirmation.store', $refundDraft), [
        'expected_revision' => 2,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $refund->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', '1700')
            ->where('selected_transaction.kind', 'refund')
            ->where('selected_transaction.receipt_breakdown.confirmed.line_items.0.description', 'Independently reviewed return')
            ->where('category_totals.0.category.name', 'Appliances')
            ->where('category_totals.0.totals.PEN', '2500')
            ->where('category_totals.1.category.name', 'Returns')
            ->where('category_totals.1.totals.PEN', '-800'));

    expect($purchaseBreakdown->refresh()->status)->toBe('confirmed')
        ->and($purchaseBreakdown->lineItems()->count())->toBe(1);
});

test('an incompatible Transaction Correction demotes its confirmed breakdown and restores fallback reporting', function (
    ReviewableTransactionField $field,
    string $correctedValue,
    string $expectedPenTotal,
    string $expectedUsdTotal,
) {
    $owner = User::factory()->create();
    $fallbackCategory = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $lineItemCategory = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()
        ->recycle($owner)
        ->purchase()
        ->pen()
        ->provisional([$field])
        ->create([
            'amount_minor' => 2500,
            'category_id' => $fallbackCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->create();
    LineItem::factory()->for($breakdown)->create([
        'category_id' => $lineItemCategory->id,
        'line_total_minor' => 2500,
    ]);

    $this->actingAs($owner)->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => $field,
    ]), [
        'expected_revision' => 1,
        'resolution' => 'correct',
        'value' => $correctedValue,
    ])->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', $expectedPenTotal)
            ->where('totals.USD', $expectedUsdTotal)
            ->where('category_totals.0.category.name', 'Shopping')
            ->where('selected_transaction.receipt_breakdown.confirmed', null)
            ->where('selected_transaction.receipt_breakdown.draft.revision', 2));

    expect($breakdown->refresh()->status)->toBe('draft')
        ->and($breakdown->confirmed_at)->toBeNull();
})->with([
    'amount' => [ReviewableTransactionField::AmountMinor, '3000', '3000', '0'],
    'currency' => [ReviewableTransactionField::Currency, 'USD', '0', '2500'],
    'kind' => [ReviewableTransactionField::Kind, 'refund', '-2500', '0'],
]);

test('confirmation rejects a draft whose Transaction became Voided', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
    ]);
    $draft = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->draft()->create();
    LineItem::factory()->for($draft)->create(['line_total_minor' => 2500]);
    $transaction->update(['voided_at' => now(), 'revision' => 2]);

    $this->actingAs($owner)
        ->post(route('receipt_breakdowns.confirmation.store', $draft), [
            'expected_revision' => 1,
        ])
        ->assertSessionHasErrors('reconciliation');

    expect($draft->refresh()->status)->toBe('draft');
});

test('combined reporting allocates one converted Transaction total across confirmed Line Items exactly', function () {
    $owner = User::factory()->create(['reporting_currency' => 'PEN']);
    $coffee = Category::factory()->recycle($owner)->create(['name' => 'Coffee']);
    $fruit = Category::factory()->recycle($owner)->create(['name' => 'Fruit']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->usd()->create([
        'occurred_on' => '2026-07-28',
        'amount_minor' => 2,
    ]);
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->create();
    LineItem::factory()->for($breakdown)->create([
        'line_item_id' => '01983d79-a780-72f0-bb34-9b4f3f0cf390',
        'category_id' => $coffee->id,
        'line_total_minor' => 1,
    ]);
    LineItem::factory()->for($breakdown)->create([
        'line_item_id' => '01983d79-a780-72f0-bb34-9b4f3f0cf391',
        'category_id' => $fruit->id,
        'line_total_minor' => 1,
    ]);
    DailyExchangeRate::factory()->recycle($owner)->create([
        'applicable_on' => '2026-07-28',
        'pen_per_usd_scaled' => 1_500_000,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.amount_minor', '3')
            ->where('category_totals.0.category.name', 'Coffee')
            ->where('category_totals.0.combined_total.amount_minor', '2')
            ->where('category_totals.1.category.name', 'Fruit')
            ->where('category_totals.1.combined_total.amount_minor', '1'));
});

test('combined reporting apportions signed adjustments by mathematical largest remainder', function () {
    $owner = User::factory()->create(['reporting_currency' => 'PEN']);
    $discounts = Category::factory()->recycle($owner)->create(['name' => 'Discounts']);
    $goods = Category::factory()->recycle($owner)->create(['name' => 'Goods']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->usd()->create([
        'occurred_on' => '2026-07-28',
        'amount_minor' => 2,
    ]);
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->create();
    LineItem::factory()->for($breakdown)->create([
        'line_item_id' => '01983d79-a780-72f0-bb34-9b4f3f0cf390',
        'category_id' => $goods->id,
        'line_total_minor' => 3,
    ]);
    LineItem::factory()->for($breakdown)->create([
        'line_item_id' => '01983d79-a780-72f0-bb34-9b4f3f0cf391',
        'description' => 'Printed discount',
        'role' => 'discount',
        'category_id' => $discounts->id,
        'line_total_minor' => -1,
    ]);
    DailyExchangeRate::factory()->recycle($owner)->create([
        'applicable_on' => '2026-07-28',
        'pen_per_usd_scaled' => 1_500_000,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.amount_minor', '3')
            ->where('category_totals.0.category.name', 'Discounts')
            ->where('category_totals.0.combined_total.amount_minor', '-2')
            ->where('category_totals.1.category.name', 'Goods')
            ->where('category_totals.1.combined_total.amount_minor', '5'));
});
