<?php

use App\Models\DailyExchangeRate;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner sets reporting preferences and sees an exact combined total', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 1,
        'merchant_description' => 'Exact rounding purchase',
    ]);
    $this->actingAs($owner);

    $page = visit(route('daily_exchange_rates.index'));

    $page
        ->assertSee('No rates recorded')
        ->select('Currency', 'PEN')
        ->press('Save Reporting Currency')
        ->assertSee('Reporting Currency updated.')
        ->fill('Applicable date', '2026-07-24')
        ->fill('PEN per USD', '3.500000')
        ->press('Add Rate')
        ->assertSee('Daily Exchange Rate created.')
        ->assertSee('Owner managed')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit(route('transactions.index'));

    $page
        ->assertSee('$ 0.01')
        ->assertSee('S/ 0.04')
        ->assertSee('Exact rounding purchase')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a stale rate form reloads the current value before it can be retried', function () {
    $owner = User::factory()->create();
    $rate = DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_500_000,
    ]);
    $this->actingAs($owner);

    $page = visit(route('daily_exchange_rates.index'));
    $page->fill("#rate-{$rate->id}", '4.000000');

    $rate->fill([
        'pen_per_usd_scaled' => 3_800_000,
        'revision' => 2,
    ])->save();

    $page
        ->press('Update')
        ->assertSee('This Daily Exchange Rate changed while you were reviewing it.')
        ->assertValue("#rate-{$rate->id}", '3.800000')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
