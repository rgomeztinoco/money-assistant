<?php

use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner records every money movement kind in plain language', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->assertSee('No matching Transactions')
        ->press('Add Transaction')
        ->press('Save Transaction')
        ->assertSee('The amount field is required.')
        ->assertSee('The description field is required.')
        ->fill('#transaction-amount', '123.45')
        ->fill('#transaction-description', 'Mortgage payment')
        ->select('#transaction-currency', 'PEN')
        ->press('Save Transaction')
        ->assertSee('S/ 123.45')
        ->assertSee('Mortgage payment')
        ->assertSee('Spending')
        ->press('Add Transaction')
        ->fill('#transaction-amount', '23.45')
        ->fill('#transaction-description', 'Travel reimbursement')
        ->select('#transaction-kind', 'refund')
        ->press('Save Transaction')
        ->assertSee('Travel reimbursement')
        ->assertSee('Refund or reimbursement')
        ->press('Add Transaction')
        ->fill('#transaction-amount', '98.76')
        ->fill('#transaction-description', 'Monthly salary')
        ->select('#transaction-kind', 'income')
        ->select('#transaction-income-source', 'salary')
        ->press('Save Transaction')
        ->assertSee('Monthly salary')
        ->assertSee('Income')
        ->press('Add Transaction')
        ->fill('#transaction-amount', '8.76')
        ->fill('#transaction-description', 'Moved to savings')
        ->select('#transaction-kind', 'transfer')
        ->select('#transaction-transfer-purpose', 'savings')
        ->press('Save Transaction')
        ->assertSee('S/ 8.76')
        ->assertSee('Moved to savings')
        ->assertSee('Transfer · Moved to savings')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner can identify a retained Voided Transaction by ID', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'amount_minor' => 12345,
            'description' => 'Mistaken market entry',
            'voided_at' => now(),
        ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->fill('#transaction-search', (string) $transaction->id)
        ->click('[data-test="transaction-search-submit"]')
        ->click('[data-test="transaction-'.$transaction->id.'"]')
        ->assertQueryStringHas('selected', (string) $transaction->id)
        ->assertSee('Mistaken market entry')
        ->assertSee('Voided')
        ->assertSee('$ 123.45')
        ->assertSee('Excluded from all period summaries while Voided.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
