<?php

use App\Currency;
use App\Models\Category;
use App\Models\DailyExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Inertia\Testing\AssertableInertia as Assert;

test('combined totals round each Transaction half-up before summing in PEN', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_500_000,
    ]);
    Transaction::factory()->count(2)->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 1,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '2')
            ->where('totals.PEN', '0')
            ->where('combined_total.currency', Currency::Pen->value)
            ->where('combined_total.amount_minor', '8')
            ->where('combined_total.unavailable_reason', null)
            ->where('combined_total.missing_rate_dates', []));
});

test('combined totals round each Transaction half-up before summing in USD', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Usd]);
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_200_000,
    ]);
    Transaction::factory()->count(2)->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 2,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '0')
            ->where('totals.PEN', '4')
            ->where('combined_total.currency', Currency::Usd->value)
            ->where('combined_total.amount_minor', '2')
            ->where('combined_total.unavailable_reason', null));
});

test('missing rates make only affected combined results unavailable', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 100,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-25',
        'amount_minor' => 250,
        'category_id' => $transport->id,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '100')
            ->where('totals.PEN', '250')
            ->where('combined_total.amount_minor', null)
            ->where('combined_total.unavailable_reason', 'missing_exchange_rates')
            ->where('combined_total.missing_rate_dates', ['2026-07-24'])
            ->where('category_totals.0.category.name', 'Food')
            ->where('category_totals.0.combined_total.amount_minor', null)
            ->where('category_totals.0.combined_total.missing_rate_dates', ['2026-07-24'])
            ->where('category_totals.1.category.name', 'Transport')
            ->where('category_totals.1.combined_total.amount_minor', '250')
            ->where('category_totals.1.combined_total.missing_rate_dates', []));
});

test('changing Reporting Currency re-expresses combined totals without altering Transactions', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 4_000_000,
    ]);
    $usdPurchase = Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 100,
    ]);
    $penRefund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 100,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.currency', Currency::Pen->value)
            ->where('combined_total.amount_minor', '300'));

    $this->put(route('reporting_currency.update'), [
        'reporting_currency' => Currency::Usd->value,
    ])->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.currency', Currency::Usd->value)
            ->where('combined_total.amount_minor', '75'));

    expect($usdPurchase->fresh())
        ->amount_minor->toBe(100)
        ->currency->toBe(Currency::Usd)
        ->kind->toBe(TransactionKind::Purchase)
        ->revision->toBe(1)
        ->and($penRefund->fresh())
        ->amount_minor->toBe(100)
        ->currency->toBe(Currency::Pen)
        ->kind->toBe(TransactionKind::Refund)
        ->revision->toBe(1);
});

test('combined totals state that Reporting Currency has not been selected', function () {
    $owner = User::factory()->create(['reporting_currency' => null]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.currency', null)
            ->where('combined_total.amount_minor', null)
            ->where('combined_total.unavailable_reason', 'reporting_currency_not_selected')
            ->where('combined_total.missing_rate_dates', []));
});

test('combined conversion remains exact beyond JavaScript safe integers', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_500_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => '9007199254740992',
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '9007199254740992')
            ->where('combined_total.amount_minor', '31525197391593472'));
});

test('combined Category totals follow current hierarchy and ignore voided Transactions', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 4_000_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 101,
        'category_id' => $dining->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 100,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-07-25',
        'amount_minor' => 1,
        'category_id' => $food->id,
        'voided_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.amount_minor', '504')
            ->where('combined_total.missing_rate_dates', [])
            ->where('category_totals.0.category.name', 'Dining')
            ->where('category_totals.0.combined_total.amount_minor', '404')
            ->where('category_totals.1.category.name', 'Food')
            ->where('category_totals.1.combined_total.amount_minor', '504'));
});

test('Transaction occurrence dates select their rates and rate edits recalculate combined views', function () {
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $firstRate = DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_000_000,
    ]);
    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-25',
        'pen_per_usd_scaled' => 4_000_000,
    ]);
    $transactions = collect([
        Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
            'occurred_on' => '2026-07-24',
            'amount_minor' => 100,
        ]),
        Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
            'occurred_on' => '2026-07-25',
            'amount_minor' => 100,
        ]),
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.amount_minor', '700'));

    $this->patch(route('daily_exchange_rates.update', $firstRate), [
        'expected_revision' => 1,
        'pen_per_usd' => '5.000000',
    ])->assertSessionHasNoErrors();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('combined_total.amount_minor', '900'));

    expect($transactions->map->fresh()->pluck('revision')->all())->toBe([1, 1])
        ->and(DailyExchangeRate::query()->count())->toBe(2)
        ->and($firstRate->fresh()->revision)->toBe(2);
});
