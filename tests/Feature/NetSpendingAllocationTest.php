<?php

use App\Actions\Reporting\NetSpendingAllocation;
use App\ExactInteger;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;

test('spending assigned to a child category rolls up to its parent', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create();
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'amount_minor' => 2_500,
        'category_id' => $child->id,
    ]);
    $categoriesById = Category::query()
        ->whereBelongsTo($owner, 'owner')
        ->get(['id', 'parent_id', 'name'])
        ->keyBy('id');

    $allocation = app(NetSpendingAllocation::class);

    expect(collect($allocation->byCategory($transaction, $categoriesById))
        ->map(fn (ExactInteger $amount): string => $amount->value())
        ->all())->toBe([
            $child->id => '2500',
            $parent->id => '2500',
        ])
        ->and(collect($allocation->byTopLevelCategory($transaction, $categoriesById))
            ->map(fn (ExactInteger $amount): string => $amount->value())
            ->all())->toBe([
                $parent->id => '2500',
            ]);
});

test('refunds reduce net spending allocations', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'amount_minor' => 800,
        'category_id' => $category->id,
    ]);
    $categoriesById = Category::query()
        ->whereBelongsTo($owner, 'owner')
        ->get(['id', 'parent_id', 'name'])
        ->keyBy('id');

    $allocations = app(NetSpendingAllocation::class)->byCategory($refund, $categoriesById);

    expect($allocations[$category->id]->value())->toBe('-800');
});

test('receipt line items replace the transaction category and preserve split allocations', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create();
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create();
    $transport = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'amount_minor' => 4_000,
        'category_id' => $transport->id,
    ]);
    $receiptBreakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    LineItem::factory()->for($receiptBreakdown)->create([
        'category_id' => $dining->id,
        'line_total_minor' => 2_500,
    ]);
    LineItem::factory()->for($receiptBreakdown)->create([
        'category_id' => null,
        'line_total_minor' => 1_500,
    ]);
    $transaction->load([
        'receiptBreakdown:id,transaction_id',
        'receiptBreakdown.lineItems:id,receipt_breakdown_id,category_id,line_total_minor',
    ]);
    $categoriesById = Category::query()
        ->whereBelongsTo($owner, 'owner')
        ->get(['id', 'parent_id', 'name'])
        ->keyBy('id');

    $allocation = app(NetSpendingAllocation::class);
    $byCategory = collect($allocation->byCategory($transaction, $categoriesById))
        ->map(fn (ExactInteger $amount): string => $amount->value())
        ->all();
    $byTopLevelCategory = collect($allocation->byTopLevelCategory($transaction, $categoriesById))
        ->map(fn (ExactInteger $amount): string => $amount->value())
        ->all();

    expect($byCategory)->toBe([
        $dining->id => '2500',
        $food->id => '2500',
        'uncategorized' => '1500',
    ])->not->toHaveKey($transport->id)
        ->and($byTopLevelCategory)->toBe([
            $food->id => '2500',
            'uncategorized' => '1500',
        ]);
});

test('categories outside the provided category map become uncategorized', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $unavailableCategory = Category::factory()->for($otherOwner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'amount_minor' => 1_200,
        'category_id' => $unavailableCategory->id,
    ]);
    $categoriesById = Category::query()
        ->whereBelongsTo($owner, 'owner')
        ->get(['id', 'parent_id', 'name'])
        ->keyBy('id');

    $allocations = app(NetSpendingAllocation::class)->byCategory($transaction, $categoriesById);

    expect($allocations)->toHaveKey('uncategorized')
        ->and($allocations['uncategorized']->value())->toBe('1200');
});
