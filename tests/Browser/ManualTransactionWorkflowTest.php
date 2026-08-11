<?php

use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner records purchases and Refunds with exact USD and PEN totals', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->assertSee('No Transactions yet')
        ->press('Record Transaction')
        ->assertSee('The amount minor field is required.')
        ->assertSee('The merchant description field is required.')
        ->fill('Amount in minor units', '12345')
        ->fill('Merchant or short description', 'USD purchase')
        ->select('Currency', 'USD')
        ->select('Transaction kind', 'purchase')
        ->press('Record Transaction')
        ->assertSee('$ 123.45')
        ->assertSee('USD purchase')
        ->fill('Amount in minor units', '2345')
        ->fill('Merchant or short description', 'USD Refund')
        ->select('Transaction kind', 'refund')
        ->press('Record Transaction')
        ->assertSee('$ 23.45')
        ->assertSee('USD Refund')
        ->fill('Amount in minor units', '9876')
        ->fill('Merchant or short description', 'PEN purchase')
        ->select('Currency', 'PEN')
        ->select('Transaction kind', 'purchase')
        ->press('Record Transaction')
        ->assertSee('S/ 98.76')
        ->assertSee('PEN purchase')
        ->fill('Amount in minor units', '876')
        ->fill('Merchant or short description', 'PEN Refund')
        ->select('Transaction kind', 'refund')
        ->press('Record Transaction')
        ->assertSee('S/ 8.76')
        ->assertSee('PEN Refund')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner can void and restore a Transaction explicitly from the ledger', function () {
    $owner = User::factory()->create();
    Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 12345,
            'merchant_description' => 'Mistaken market entry',
        ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->assertSee('Mistaken market entry')
        ->assertSee('$ 123.45')
        ->press('Void')
        ->assertSee('Transaction voided.')
        ->assertSee('Voided')
        ->assertSee('Mistaken market entry')
        ->assertSee('$ 123.45')
        ->press('Restore')
        ->assertSee('Transaction restored.')
        ->assertSee('$ 123.45')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the browser makes a stale void response explicit without changing the Transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create(['merchant_description' => 'Current market entry']);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->script(
            "document.querySelector('input[name=\"expected_revision\"]').value = '2'",
        );

    $page
        ->press('Void')
        ->assertSee(
            'This Transaction changed before its void state could be updated. Review the current ledger and try again.',
        )
        ->assertSee('Current market entry')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($transaction->refresh()->revision)->toBe(1)
        ->and($transaction->voided_at)->toBeNull();
});
