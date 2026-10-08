<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\YearlyPayment;
use App\Models\YearlyPaymentSetting;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('an owner can add a yearly payment and see annual and monthly targets', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post('/yearly-payments', [
        'name' => 'Insurance', 'amount' => '2400.00', 'currency' => 'PEN',
    ])->assertRedirect('/yearly-payments');

    $this->get('/yearly-payments')->assertInertia(fn (Assert $page) => $page
        ->component('yearly-payments/index')
        ->where('plan.commitments.0.name', 'Insurance')
        ->where('plan.commitments.0.amount_minor', '240000')
        ->where('plan.commitments.0.cushion_minor', '0')
        ->where('plan.native_targets.0.currency', 'PEN')
        ->where('plan.native_targets.0.annual_target_minor', '240000')
        ->where('plan.native_targets.0.monthly_recommendation_minor', '20000')
        ->where('plan.native_targets.1.annual_target_minor', '0'));
});

test('editing pausing and resuming preserves the commitment and immediately recalculates aggregate targets', function () {
    $owner = User::factory()->create();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create();
    YearlyPayment::factory()->for($owner, 'owner')->create(['amount_minor' => 1]);

    $this->actingAs($owner)->put(route('yearly_payments.update', $payment), [
        'name' => 'Medical estimate', 'amount' => '10.00', 'cushion' => '2.01', 'currency' => 'PEN',
    ])->assertRedirect(route('yearly_payments.index'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.commitments.0.target_minor', '1201')
        ->where('plan.native_targets.0.annual_target_minor', '1202')
        ->where('plan.native_targets.0.monthly_recommendation_minor', '101'));

    $this->put('/yearly-payments/'.$payment->id.'/state', ['is_active' => false])->assertRedirect(route('yearly_payments.index'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->has('plan.commitments', 2)
        ->where('plan.commitments.0.is_active', false)
        ->where('plan.commitments.0.target_minor', '1201')
        ->where('plan.native_targets.0.annual_target_minor', '1'));
    $this->put('/yearly-payments/'.$payment->id.'/state', ['is_active' => true])->assertRedirect(route('yearly_payments.index'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.commitments.0.is_active', true)
        ->where('plan.native_targets.0.annual_target_minor', '1202'));
});

test('manual conversion keeps currencies separate and rounds the final annual estimate and monthly recommendation upward', function () {
    $owner = User::factory()->create();
    YearlyPayment::factory()->for($owner, 'owner')->create(['amount_minor' => 9007199254740993]);
    YearlyPayment::factory()->for($owner, 'owner')->create(['amount_minor' => 1]);
    YearlyPayment::factory()->for($owner, 'owner')->create(['currency' => 'USD', 'amount_minor' => 1]);
    YearlyPayment::factory()->for($owner, 'owner')->paused()->create(['amount_minor' => 1000000]);

    $this->actingAs($owner)->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.native_targets.0.annual_target_minor', '9007199254740994')
        ->where('plan.native_targets.0.monthly_recommendation_minor', '750599937895083')
        ->where('plan.native_targets.1.annual_target_minor', '1')
        ->where('plan.combined_estimate.status', 'unavailable')
        ->where('plan.planning_rate.pen_per_usd', null));
    $this->put('/yearly-payments/planning-rate', ['pen_per_usd' => '3.500000000000000001'])->assertRedirect(route('yearly_payments.index'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.native_targets.0.annual_target_minor', '9007199254740994')
        ->where('plan.native_targets.1.annual_target_minor', '1')
        ->where('plan.combined_estimate.status', 'available')
        ->where('plan.combined_estimate.annual_target_minor', '9007199254740998')
        ->where('plan.combined_estimate.monthly_recommendation_minor', '750599937895084')
        ->where('plan.planning_rate.pen_per_usd', '3.500000000000000001')
        ->where('plan.planning_rate.direction', 'PEN per USD')
        ->where('plan.planning_rate.source', 'manual'));
    $this->put('/yearly-payments/planning-rate', ['pen_per_usd' => '4.01'])->assertRedirect(route('yearly_payments.index'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.native_targets.0.annual_target_minor', '9007199254740994')
        ->where('plan.combined_estimate.annual_target_minor', '9007199254740999'));
    $this->put('/yearly-payments/planning-rate', [])->assertRedirect(route('yearly_payments.index'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.planning_rate.pen_per_usd', null)
        ->where('plan.combined_estimate.status', 'unavailable'));
});

test('Home shows upcoming active payments in the reporting timezone independently of Transactions and historical filters', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 02:00:00', 'UTC'));
    $owner = User::factory()->create();
    $today = YearlyPayment::factory()->for($owner, 'owner')->create(['expected_due_on' => '2026-10-08']);
    $boundary = YearlyPayment::factory()->for($owner, 'owner')->create(['expected_due_on' => '2026-11-07']);
    $sameDate = YearlyPayment::factory()->for($owner, 'owner')->create(['expected_due_on' => '2026-10-08']);
    foreach (['2026-10-07', '2026-11-08', null, '2028-01-01'] as $due) {
        YearlyPayment::factory()->for($owner, 'owner')->create(['expected_due_on' => $due]);
    }
    YearlyPayment::factory()->for($owner, 'owner')->paused()->create(['expected_due_on' => '2026-10-08']);
    YearlyPayment::factory()->create(['expected_due_on' => '2026-10-08']);

    $this->actingAs($owner)->get(route('home', ['period' => 'month', 'anchor' => '2020-01-01', 'currency' => 'USD']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary', null)
            ->where('yearly_payment_plan.calculation_date', '2026-10-08')
            ->where('yearly_payment_plan.timezone', 'America/Lima')
            ->has('yearly_payment_plan.upcoming_commitments', 3)
            ->where('yearly_payment_plan.upcoming_commitments.0.id', $today->id)
            ->where('yearly_payment_plan.upcoming_commitments.1.id', $sameDate->id)
            ->where('yearly_payment_plan.upcoming_commitments.2.id', $boundary->id)
            ->where('yearly_payment_plan.native_targets.0.annual_target_minor', '1680000'));

    $this->travel(32)->days();
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.upcoming_commitments', [])
        ->where('plan.native_targets.0.annual_target_minor', '1680000')
        ->where('plan.native_targets.0.monthly_recommendation_minor', '140000'));
    expect($today->fresh())->expected_due_on->toDateString()->toBe('2026-10-08')->is_active->toBeTrue();
});

test('empty and all-paused plans have zero targets without requiring an exchange rate', function (bool $paused) {
    $owner = User::factory()->create();
    if ($paused) {
        YearlyPayment::factory()->for($owner, 'owner')->paused()->create(['currency' => 'USD']);
    }

    $this->actingAs($owner)->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.native_targets', [
            ['currency' => 'PEN', 'annual_target_minor' => '0', 'monthly_recommendation_minor' => '0'],
            ['currency' => 'USD', 'annual_target_minor' => '0', 'monthly_recommendation_minor' => '0'],
        ])
        ->where('plan.combined_estimate.status', 'available')
        ->where('plan.combined_estimate.annual_target_minor', '0')
        ->where('plan.combined_estimate.monthly_recommendation_minor', '0')
        ->where('plan.upcoming_commitments', []));
})->with(['empty' => false, 'all paused' => true]);

test('invalid yearly payment inputs return useful feedback without saving', function (string $field, mixed $value, string $message) {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('yearly_payments.store'), [
        'name' => 'Insurance', 'amount' => '2400.00', 'currency' => 'PEN', $field => $value,
    ])->assertSessionHasErrors([$field => $message]);

    $this->assertDatabaseCount('yearly_payments', 0);
})->with([
    'missing name' => ['name', null, 'The name field is required.'],
    'blank name' => ['name', '   ', 'The name field is required.'],
    'missing amount' => ['amount', null, 'The amount field is required.'],
    'zero amount' => ['amount', '0', 'The amount must be greater than zero.'],
    'negative amount' => ['amount', '-1', 'The amount must be greater than zero.'],
    'overprecision amount' => ['amount', '1.001', 'The amount must use currency units with at most two decimal places.'],
    'invalid amount' => ['amount', 'abc', 'The amount must use currency units with at most two decimal places.'],
    'float amount' => ['amount', 1.25, 'The amount field must be a string.'],
    'oversized amount' => ['amount', '92233720368547758.08', 'The amount is too large.'],
    'unsupported currency' => ['currency', 'EUR', 'The selected currency is invalid.'],
    'negative cushion' => ['cushion', '-0.01', 'The cushion must be a nonnegative amount with at most two decimal places.'],
    'invalid cushion' => ['cushion', 'abc', 'The amount must use currency units with at most two decimal places.'],
    'overprecision cushion' => ['cushion', '0.001', 'The amount must use currency units with at most two decimal places.'],
    'float cushion' => ['cushion', 0.01, 'The cushion field must be a string.'],
    'invalid date' => ['expected_due_on', 'next year', 'The expected due on field must match the format Y-m-d.'],
    'impossible date' => ['expected_due_on', '2026-02-30', 'The expected due on field must match the format Y-m-d.'],
]);

test('invalid manual rates cannot change the saved assumption', function (mixed $value) {
    $owner = User::factory()->create();
    $setting = YearlyPaymentSetting::factory()->for($owner, 'owner')->create(['pen_per_usd' => '3.7500']);

    $this->actingAs($owner)->put(route('yearly_payments.planning_rate.update'), ['pen_per_usd' => $value])
        ->assertSessionHasErrors('pen_per_usd');

    expect($setting->fresh()->pen_per_usd)->toBe('3.7500');
})->with(['zero' => '0', 'decimal zero' => '0.000', 'negative' => '-1', 'invalid' => 'abc', 'scientific' => '1e2', 'float' => 3.75, 'oversized' => str_repeat('1', 256)]);

test('yearly payment reads and writes isolate owners and require authentication', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $private = YearlyPayment::factory()->for($other, 'owner')->create(['name' => 'Private insurance']);
    $setting = YearlyPaymentSetting::factory()->for($other, 'owner')->create(['pen_per_usd' => '999']);

    $this->get(route('yearly_payments.index'))->assertRedirect(route('login'));
    $this->post(route('yearly_payments.store'), [])->assertRedirect(route('login'));
    $this->put(route('yearly_payments.update', $private), [])->assertRedirect(route('login'));
    $this->put(route('yearly_payments.state.update', $private), ['is_active' => false])->assertRedirect(route('login'));
    $this->put(route('yearly_payments.planning_rate.update'), ['pen_per_usd' => '1'])->assertRedirect(route('login'));
    $this->actingAs($owner)->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.commitments', [])->where('plan.planning_rate.pen_per_usd', null));
    $this->put(route('yearly_payments.update', $private), ['name' => 'Changed', 'amount' => '1', 'currency' => 'PEN'])->assertForbidden();
    $this->put(route('yearly_payments.state.update', $private), ['is_active' => false])->assertForbidden();
    $this->post(route('yearly_payments.store'), ['user_id' => $other->id, 'name' => 'My payment', 'amount' => '1', 'currency' => 'USD'])->assertRedirect();
    $this->put(route('yearly_payments.planning_rate.update'), ['user_id' => $other->id, 'pen_per_usd' => '3'])->assertRedirect();

    expect($private->fresh())->name->toBe('Private insurance')->is_active->toBeTrue();
    expect($setting->fresh()->pen_per_usd)->toBe('999');
    $this->assertDatabaseHas('yearly_payments', ['user_id' => $owner->id, 'name' => 'My payment']);
    $this->assertDatabaseHas('yearly_payment_settings', ['user_id' => $owner->id, 'pen_per_usd' => '3']);
});

test('planning changes including due dates never alter confirmed Transactions or historical summaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Lima'));
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['amount_minor' => 1000, 'occurred_on' => '2026-10-01']);
    Transaction::factory()->for($owner, 'owner')->income()->pen()->create(['amount_minor' => 5000, 'occurred_on' => '2026-10-02']);
    Transaction::factory()->for($owner, 'owner')->transfer()->pen()->create(['amount_minor' => 2000, 'occurred_on' => '2026-10-03', 'direction' => 'debit', 'transfer_purpose' => 'savings']);
    $before = Transaction::query()->get()->toArray();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->put(route('yearly_payments.update', $payment), ['name' => 'Insurance', 'amount' => '2400', 'currency' => 'PEN', 'expected_due_on' => '2026-10-08'])->assertRedirect();
    $this->put(route('yearly_payments.state.update', $payment), ['is_active' => false])->assertRedirect();
    $this->put(route('yearly_payments.state.update', $payment), ['is_active' => true])->assertRedirect();
    $this->put(route('yearly_payments.planning_rate.update'), ['pen_per_usd' => '3.75'])->assertRedirect();
    $this->put(route('yearly_payments.update', $payment), ['name' => 'Insurance', 'amount' => '2400', 'currency' => 'PEN', 'expected_due_on' => '2028-01-01'])->assertRedirect();
    $this->post(route('yearly_payments.store'), ['name' => 'Course', 'amount' => '120', 'currency' => 'USD'])->assertRedirect();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('primary.summary', ['net_spending_minor' => '1000', 'income_minor' => '5000', 'moved_to_savings_minor' => '2000'])
        ->where('yearly_payment_plan.native_targets.0.annual_target_minor', '240000')
        ->where('yearly_payment_plan.native_targets.0.monthly_recommendation_minor', '20000')
        ->where('yearly_payment_plan.upcoming_commitments', []));

    expect(Transaction::query()->get()->toArray())->toBe($before);
});

test('conversion rounds the aggregate rather than each payment', function () {
    $owner = User::factory()->create();
    YearlyPayment::factory()->count(2)->for($owner, 'owner')->create(['amount_minor' => 1, 'currency' => 'USD']);
    YearlyPaymentSetting::factory()->for($owner, 'owner')->create(['pen_per_usd' => '3.5']);

    $this->actingAs($owner)->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->where('plan.native_targets.1.annual_target_minor', '2')
        ->where('plan.native_targets.1.monthly_recommendation_minor', '1')
        ->where('plan.combined_estimate.annual_target_minor', '7')
        ->where('plan.combined_estimate.monthly_recommendation_minor', '1'));
});

test('invalid edits and state changes leave the stored commitment intact', function () {
    $owner = User::factory()->create();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create(['name' => 'Insurance']);
    $before = $payment->toArray();

    $this->actingAs($owner)->put(route('yearly_payments.update', $payment), ['name' => 'Changed', 'amount' => '0', 'currency' => 'PEN'])
        ->assertSessionHasErrors(['amount' => 'The amount must be greater than zero.']);
    $this->put(route('yearly_payments.state.update', $payment), ['is_active' => 'paused'])
        ->assertSessionHasErrors('is_active');

    expect($payment->fresh()->toArray())->toBe($before);
});
