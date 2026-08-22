<?php

use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner records every money movement meaning in plain language', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->assertSee('No Transactions yet')
        ->press('Record Transaction')
        ->assertSee('The amount field is required.')
        ->assertSee('The merchant description field is required.')
        ->fill('Amount', '123.45')
        ->fill('Merchant or short description', 'Mortgage payment')
        ->select('Currency', 'PEN')
        ->select('Movement meaning', 'spending')
        ->select('Money direction', 'debit')
        ->press('Record Transaction')
        ->assertSee('S/ 123.45')
        ->assertSee('Mortgage payment')
        ->assertSee('Spending')
        ->fill('Amount', '23.45')
        ->fill('Merchant or short description', 'Travel reimbursement')
        ->select('Movement meaning', 'refund')
        ->select('Money direction', 'credit')
        ->press('Record Transaction')
        ->assertSee('Travel reimbursement')
        ->assertSee('Refund or reimbursement')
        ->fill('Amount', '98.76')
        ->fill('Merchant or short description', 'Monthly salary')
        ->select('Movement meaning', 'income')
        ->select('Income source', 'salary')
        ->press('Record Transaction')
        ->assertSee('Monthly salary')
        ->assertSee('Income')
        ->fill('Amount', '8.76')
        ->fill('Merchant or short description', 'Moved to savings')
        ->select('Movement meaning', 'transfer')
        ->select('Money direction', 'debit')
        ->select('Transfer purpose', 'savings')
        ->press('Record Transaction')
        ->assertSee('S/ 8.76')
        ->assertSee('Moved to savings')
        ->assertSee('Transfer · Moved to savings')
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
