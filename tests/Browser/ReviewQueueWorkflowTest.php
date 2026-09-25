<?php

use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('a saved Review Queue bookmark opens the current Transaction inspector', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::Description])
        ->create(['description' => 'Review me']);
    $this->actingAs($owner);

    visit('/review-queue?item=transaction:'.$transaction->id)
        ->assertQueryStringHas('selected', (string) $transaction->id)
        ->assertSee('Edit Transaction')
        ->assertSee('Review me')
        ->press('Close')
        ->assertQueryStringMissing('selected')
        ->assertSee('Review me')
        ->assertNoJavaScriptErrors();
});

test('a saved Line Item bookmark opens its owning Transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->create();
    $lineItem = LineItem::factory()->for($breakdown)->create();
    $this->actingAs($owner);

    visit('/review-queue?item=line-item:'.$lineItem->id)
        ->assertQueryStringHas('selected', (string) $transaction->id)
        ->assertSee('Receipt Breakdown')
        ->assertNoJavaScriptErrors();
});
