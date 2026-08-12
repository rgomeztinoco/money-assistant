<?php

use App\Models\DailyExchangeRate;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('exchange-rate maintenance does not combine the Dashboard currency totals', function () {
    $owner = User::factory()->create();
    $today = now()->toDateString();
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => $today,
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
        ->fill('Applicable date', $today)
        ->fill('PEN per USD', '3.500000')
        ->press('Add Rate')
        ->assertSee('Daily Exchange Rate created.')
        ->assertSee('Owner managed')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page = visit(route('dashboard'));

    $page
        ->assertSee('$ 0.01')
        ->assertSee('S/ 0.00')
        ->assertDontSee('S/ 0.04')
        ->assertDontSee('Combined spending')
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

test('a seeded rate displays BCRP sell attribution and distinct source metadata', function () {
    $owner = User::factory()->create();
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-26',
        'pen_per_usd_scaled' => 3_545_000,
        'owner_managed_at' => null,
        'source' => 'bcrp_data',
        'source_series' => 'PD04638PD',
        'source_observed_on' => '2026-07-24',
        'source_retrieved_at' => '2026-07-27 15:00:00+00',
        'source_value' => '3.545',
        'source_precision' => 3,
    ]);
    $this->actingAs($owner);

    $page = visit(route('daily_exchange_rates.index'));

    $page
        ->assertSee('BCRP interbank sell')
        ->assertSee('Banco Central de Reserva del Peru, BCRPData series PD04638PD')
        ->assertSee('Applicable date: 2026-07-26')
        ->assertSee('Source observation date: 2026-07-24')
        ->assertSee('Source value: 3.545 (declared precision: 3 decimal places)')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
