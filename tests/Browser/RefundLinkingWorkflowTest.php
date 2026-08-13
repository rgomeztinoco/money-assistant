<?php

use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner links a Refund and sees an excessive relationship in the Review Queue', function () {
    $owner = User::factory()->create();
    $purchase = Transaction::factory()
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 10_000,
            'merchant_description' => 'Original purchase',
        ]);
    Transaction::factory()
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 12_000,
            'merchant_description' => 'Store Refund',
        ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->press('Inspect')
        ->select('Edit original purchase', (string) $purchase->id)
        ->press('Save Transaction')
        ->assertSee('Transaction updated.');

    visit('/review-queue')
        ->assertSee('Linked Refunds exceed the purchase')
        ->assertSee('The confirmed Refunds remain included.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
