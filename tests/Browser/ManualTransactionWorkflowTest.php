<?php

use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner records a Spending after a validation error', function () {
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
        ->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')
        ->assertQueryStringHas('selected', (string) $transaction->id)
        ->assertSee('Mistaken market entry')
        ->assertSee('Voided')
        ->assertValue('#transaction-amount', '123.45')
        ->assertSelected('#transaction-currency', 'USD')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
