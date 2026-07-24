<?php

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
        ->assertSee('$ 100.00')
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
        ->assertSee('S/ 90.00')
        ->assertSee('PEN Refund')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
