<?php

use App\Actions\Reporting\ReadSpendingSummary;
use App\Currency;
use App\Models\Category;
use App\Models\DailyExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;

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

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['totals'])->toBe(['USD' => '2', 'PEN' => '0'])
        ->and($summary['combined_total']['currency'])->toBe(Currency::Pen->value)
        ->and($summary['combined_total']['amount_minor'])->toBe('8')
        ->and($summary['combined_total']['unavailable_reason'])->toBeNull()
        ->and($summary['combined_total']['missing_rate_dates'])->toBe([]);
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

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['totals'])->toBe(['USD' => '0', 'PEN' => '4'])
        ->and($summary['combined_total']['currency'])->toBe(Currency::Usd->value)
        ->and($summary['combined_total']['amount_minor'])->toBe('2')
        ->and($summary['combined_total']['unavailable_reason'])->toBeNull();
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

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['totals'])->toBe(['USD' => '100', 'PEN' => '250'])
        ->and($summary['combined_total']['amount_minor'])->toBeNull()
        ->and($summary['combined_total']['unavailable_reason'])->toBe('missing_exchange_rates')
        ->and($summary['combined_total']['missing_rate_dates'])->toBe(['2026-07-24'])
        ->and(data_get($summary, 'category_totals.0.category.name'))->toBe('Food')
        ->and(data_get($summary, 'category_totals.0.combined_total.amount_minor'))->toBeNull()
        ->and(data_get($summary, 'category_totals.0.combined_total.missing_rate_dates'))->toBe(['2026-07-24'])
        ->and(data_get($summary, 'category_totals.1.category.name'))->toBe('Transport')
        ->and(data_get($summary, 'category_totals.1.combined_total.amount_minor'))->toBe('250')
        ->and(data_get($summary, 'category_totals.1.combined_total.missing_rate_dates'))->toBe([]);
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

    $this->actingAs($owner);

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['combined_total']['currency'])->toBe(Currency::Pen->value)
        ->and($summary['combined_total']['amount_minor'])->toBe('300');

    $this->put(route('reporting_currency.update'), [
        'reporting_currency' => Currency::Usd->value,
    ])->assertSessionHasNoErrors();

    $summary = app(ReadSpendingSummary::class)->handle($owner->refresh());

    expect($summary['combined_total']['currency'])->toBe(Currency::Usd->value)
        ->and($summary['combined_total']['amount_minor'])->toBe('75');

    expect($usdPurchase->fresh())
        ->amount_minor->toBe(100)
        ->currency->toBe(Currency::Usd)
        ->kind->toBe(TransactionKind::Purchase)
        ->and($penRefund->fresh())
        ->amount_minor->toBe(100)
        ->currency->toBe(Currency::Pen)
        ->kind->toBe(TransactionKind::Refund);
});

test('combined totals state that Reporting Currency has not been selected', function () {
    $owner = User::factory()->create(['reporting_currency' => null]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create();

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['combined_total']['currency'])->toBeNull()
        ->and($summary['combined_total']['amount_minor'])->toBeNull()
        ->and($summary['combined_total']['unavailable_reason'])->toBe('reporting_currency_not_selected')
        ->and($summary['combined_total']['missing_rate_dates'])->toBe([]);
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

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['totals']['USD'])->toBe('9007199254740992')
        ->and($summary['combined_total']['amount_minor'])->toBe('31525197391593472');
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

    $summary = app(ReadSpendingSummary::class)->handle($owner);

    expect($summary['combined_total']['amount_minor'])->toBe('504')
        ->and($summary['combined_total']['missing_rate_dates'])->toBe([])
        ->and(data_get($summary, 'category_totals.0.category.name'))->toBe('Dining')
        ->and(data_get($summary, 'category_totals.0.combined_total.amount_minor'))->toBe('404')
        ->and(data_get($summary, 'category_totals.1.category.name'))->toBe('Food')
        ->and(data_get($summary, 'category_totals.1.combined_total.amount_minor'))->toBe('504');
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

    $this->actingAs($owner);

    expect(app(ReadSpendingSummary::class)->handle($owner)['combined_total']['amount_minor'])
        ->toBe('700');

    $this->patch(route('daily_exchange_rates.update', $firstRate), [
        'expected_revision' => 1,
        'pen_per_usd' => '5.000000',
    ])->assertSessionHasNoErrors();

    expect(app(ReadSpendingSummary::class)->handle($owner)['combined_total']['amount_minor'])
        ->toBe('900');

    expect($transactions->map->fresh()->pluck('amount_minor')->all())->toBe([100, 100])
        ->and(DailyExchangeRate::query()->count())->toBe(2)
        ->and($firstRate->fresh()->revision)->toBe(2);
});
