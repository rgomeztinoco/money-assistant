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
        ->press('Record Transaction')
        ->assertSee('The amount field is required.')
        ->assertSee('The description field is required.')
        ->fill('#manual-amount', '123.45')
        ->fill('#manual-description', 'Mortgage payment')
        ->select('#manual-currency', 'PEN')
        ->select('#manual-kind', 'spending')
        ->select('#manual-direction', 'debit')
        ->press('Record Transaction')
        ->assertNotPresent('#manual-amount')
        ->assertSee('S/ 123.45')
        ->assertSee('Mortgage payment')
        ->assertSee('Spending')
        ->navigate('/transactions')
        ->press('Add Transaction')
        ->fill('#manual-amount', '23.45')
        ->fill('#manual-description', 'Travel reimbursement')
        ->select('#manual-kind', 'refund')
        ->select('#manual-direction', 'credit')
        ->press('Record Transaction')
        ->assertNotPresent('#manual-amount')
        ->assertSee('Travel reimbursement')
        ->assertSee('Refund or reimbursement')
        ->navigate('/transactions')
        ->press('Add Transaction')
        ->fill('#manual-amount', '98.76')
        ->fill('#manual-description', 'Monthly salary')
        ->select('#manual-kind', 'income')
        ->select('#manual-income-source', 'salary')
        ->press('Record Transaction')
        ->assertNotPresent('#manual-amount')
        ->assertSee('Monthly salary')
        ->assertSee('Income')
        ->navigate('/transactions')
        ->press('Add Transaction')
        ->fill('#manual-amount', '8.76')
        ->fill('#manual-description', 'Moved to savings')
        ->select('#manual-kind', 'transfer')
        ->select('#manual-direction', 'debit')
        ->select('#manual-transfer-purpose', 'savings')
        ->press('Record Transaction')
        ->assertNotPresent('#manual-amount')
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
