<?php

use App\Currency;
use App\Models\DailyExchangeRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can choose a Reporting Currency and maintain exact Daily Exchange Rates', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('daily_exchange_rates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('daily-exchange-rates/index')
            ->where('reporting_currency', null)
            ->where('rates', []));

    $this->put(route('reporting_currency.update'), [
        'reporting_currency' => Currency::Pen->value,
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('daily_exchange_rates.index'));

    $this->post(route('daily_exchange_rates.store'), [
        'applicable_on' => '2026-07-24',
        'pen_per_usd' => '3.725',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('daily_exchange_rates.index'));

    $rate = DailyExchangeRate::query()->sole();

    expect($owner->fresh()->reporting_currency)->toBe(Currency::Pen)
        ->and($rate->applicable_on->toDateString())->toBe('2026-07-24')
        ->and($rate->pen_per_usd_scaled)->toBe(3_725_000)
        ->and($rate->owner_managed_at)->not->toBeNull()
        ->and($rate->revision)->toBe(1);

    $this->patch(route('daily_exchange_rates.update', $rate), [
        'expected_revision' => 1,
        'pen_per_usd' => '3.800001',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('daily_exchange_rates.index'));

    expect($rate->fresh())
        ->pen_per_usd_scaled->toBe(3_800_001)
        ->revision->toBe(2);

    $this->get(route('daily_exchange_rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reporting_currency', Currency::Pen->value)
            ->has('rates', 1)
            ->where('rates.0.applicable_on', '2026-07-24')
            ->where('rates.0.pen_per_usd', '3.800001')
            ->where('rates.0.revision', 2)
            ->where('rates.0.owner_managed', true));
});

test('a stale Daily Exchange Rate edit fails closed', function () {
    $owner = User::factory()->create();
    $rate = DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'pen_per_usd_scaled' => 3_725_000,
        'revision' => 2,
    ]);

    $this->actingAs($owner)
        ->patch(route('daily_exchange_rates.update', $rate), [
            'expected_revision' => 1,
            'pen_per_usd' => '4.000000',
        ])
        ->assertSessionHasErrors('expected_revision');

    expect($rate->fresh())
        ->pen_per_usd_scaled->toBe(3_725_000)
        ->revision->toBe(2);
});

test('duplicate applicable dates are rejected without replacing the existing rate', function () {
    $owner = User::factory()->create();
    $rate = DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_725_000,
    ]);

    $this->actingAs($owner)
        ->post(route('daily_exchange_rates.store'), [
            'applicable_on' => '2026-07-24',
            'pen_per_usd' => '4.000000',
        ])
        ->assertSessionHasErrors('applicable_on');

    expect(DailyExchangeRate::query()->count())->toBe(1)
        ->and($rate->fresh()->pen_per_usd_scaled)->toBe(3_725_000);
});

test('Daily Exchange Rate input rejects values that are not positive decimals with at most six places', function (mixed $value) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('daily_exchange_rates.store'), [
            'applicable_on' => '2026-07-24',
            'pen_per_usd' => $value,
        ])
        ->assertSessionHasErrors('pen_per_usd');

    expect(DailyExchangeRate::query()->count())->toBe(0);
})->with([
    'zero' => '0',
    'negative' => '-3.75',
    'more than six decimal places' => '3.7500001',
    'outside the scaled integer range' => '9223372036854.775808',
    'floating point transport value' => 3.75,
    'not numeric' => 'rate',
]);

test('PostgreSQL constraints protect Daily Exchange Rate invariants', function (array $attributes) {
    $owner = User::factory()->create();

    expect(fn () => DB::table((new DailyExchangeRate)->getTable())->insert([
        'user_id' => $owner->id,
        'applicable_on' => '2026-07-24',
        'pen_per_usd_scaled' => 3_725_000,
        'owner_managed_at' => now(),
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]))->toThrow(QueryException::class);
})->with([
    'non-positive scaled rate' => [['pen_per_usd_scaled' => 0]],
    'non-positive revision' => [['revision' => 0]],
    'missing owner or BCRP authority' => [['owner_managed_at' => null]],
]);

test('Daily Exchange Rate and Reporting Currency routes require an authenticated owner', function () {
    $owner = User::factory()->create();
    $rate = DailyExchangeRate::factory()->for($owner, 'owner')->create();

    $this->get(route('daily_exchange_rates.index'))->assertRedirect(route('login'));
    $this->post(route('daily_exchange_rates.store'), [])->assertRedirect(route('login'));
    $this->patch(route('daily_exchange_rates.update', $rate), [])->assertRedirect(route('login'));
    $this->put(route('reporting_currency.update'), [])->assertRedirect(route('login'));
});
