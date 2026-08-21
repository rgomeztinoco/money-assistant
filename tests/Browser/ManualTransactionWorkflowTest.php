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
        ->assertSee('The amount field is required.')
        ->assertSee('The merchant description field is required.')
        ->fill('Amount', '123.45')
        ->fill('Merchant or short description', 'USD purchase')
        ->select('Currency', 'USD')
        ->select('Transaction kind', 'purchase')
        ->press('Record Transaction')
        ->assertSee('$ 123.45')
        ->assertSee('USD purchase')
        ->fill('Amount', '23.45')
        ->fill('Merchant or short description', 'USD Refund')
        ->select('Transaction kind', 'refund')
        ->press('Record Transaction')
        ->assertSee('$ 23.45')
        ->assertSee('USD Refund')
        ->fill('Amount', '98.76')
        ->fill('Merchant or short description', 'PEN purchase')
        ->select('Currency', 'PEN')
        ->select('Transaction kind', 'purchase')
        ->press('Record Transaction')
        ->assertSee('S/ 98.76')
        ->assertSee('PEN purchase')
        ->fill('Amount', '8.76')
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
