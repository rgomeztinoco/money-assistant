<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\YearlyPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('a past Spending Transaction creates an editable independent yearly payment and preserves workspace', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['description' => 'Insurance', 'amount_minor' => 100000, 'occurred_on' => '2025-09-10']);
    $before = $transaction->only(['kind', 'amount_minor', 'currency', 'occurred_on', 'description', 'confirmed_at']);
    $workspace = route('breakdown.index', ['anchor' => '2025-09-01', 'currency' => 'PEN']);

    $this->actingAs($owner)->withHeaders(['X-Inertia' => 'true', 'Referer' => $workspace])->post(route('transactions.yearly_payment.store', $transaction), [
        'name' => 'Annual insurance', 'amount' => '1200.00', 'currency' => 'USD', 'cushion' => '20', 'due_month_day' => '09-10',
    ])->assertRedirect($workspace);

    $payment = $transaction->fresh()->yearlyPayment;
    expect($transaction->fresh()->only(array_keys($before)))->toEqual($before);
    $this->assertDatabaseHas('yearly_payments', ['id' => $payment->id, 'user_id' => $owner->id, 'name' => 'Annual insurance', 'amount_minor' => 120000, 'currency' => 'USD', 'cushion_minor' => 2000, 'due_month_day' => '09-10']);
    $transaction->update(['amount_minor' => 900, 'description' => 'Corrected', 'voided_at' => now()]);
    expect($payment->fresh())->amount_minor->toBe(120000)->name->toBe('Annual insurance');
});

test('many Transactions link to one commitment without changing targets or suppressing reminders', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 12:00:00', 'America/Lima'));
    $owner = User::factory()->create();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create(['due_month_day' => '01-05']);
    $transactions = Transaction::factory()->count(2)->for($owner, 'owner')->spending()->create();

    foreach ($transactions as $transaction) {
        $this->actingAs($owner)->post(route('transactions.yearly_payment.store', $transaction), ['yearly_payment_id' => $payment->id])->assertRedirect();
    }

    expect($payment->transactions()->count())->toBe(2);
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page
        ->has('plan.commitments', 1)
        ->where('plan.native_targets.0.annual_target_minor', '240000')
        ->where('plan.upcoming_commitments.0.id', $payment->id)
        ->where('plan.upcoming_commitments.0.due_month_day', '01-05')
        ->where('plan.upcoming_commitments.0.next_due_on', '2027-01-05'));
    $this->getJson(route('transactions.yearly_payment.show', $transactions->first()))->assertJsonPath('yearly_payment_id', $payment->id)->assertJsonCount(1, 'commitments');
});

test('a linked Transaction cannot create another commitment or silently move to another commitment', function () {
    $owner = User::factory()->create();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create();
    $other = YearlyPayment::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->create(['yearly_payment_id' => $payment->id]);

    $this->actingAs($owner)->post(route('transactions.yearly_payment.store', $transaction), ['name' => 'Duplicate', 'amount' => '10', 'currency' => 'PEN'])->assertSessionHasErrors('yearly_payment_id');
    $this->post(route('transactions.yearly_payment.store', $transaction), ['yearly_payment_id' => $other->id])->assertSessionHasErrors('yearly_payment_id');

    expect($transaction->fresh()->yearly_payment_id)->toBe($payment->id);
    $this->assertDatabaseCount('yearly_payments', 2);
});

test('yearly payment actions reject nonspending and voided Transactions', function (string $state, bool $voided) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->{$state}()->create(['voided_at' => $voided ? now() : null]);

    $this->actingAs($owner)->getJson(route('transactions.yearly_payment.show', $transaction))->assertForbidden();
    $this->post(route('transactions.yearly_payment.store', $transaction), ['name' => 'Insurance', 'amount' => '10', 'currency' => 'PEN'])->assertForbidden();

    $this->assertDatabaseCount('yearly_payments', 0);
})->with(['income' => ['income', false], 'refund' => ['refund', false], 'transfer' => ['transfer', false], 'voided' => ['spending', true]]);

test('yearly payment actions isolate owners and require authentication', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->create();
    $private = YearlyPayment::factory()->create();

    $this->get(route('transactions.yearly_payment.show', $transaction))->assertRedirect(route('login'));
    $this->post(route('transactions.yearly_payment.store', $transaction))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->getJson(route('transactions.yearly_payment.show', $transaction))->assertForbidden();
    $this->post(route('transactions.yearly_payment.store', $transaction), ['name' => 'Insurance', 'amount' => '10', 'currency' => 'PEN'])->assertForbidden();
    $this->actingAs($owner)->getJson(route('transactions.yearly_payment.show', $transaction))->assertJsonCount(0, 'commitments');
    $this->post(route('transactions.yearly_payment.store', $transaction), ['yearly_payment_id' => $private->id])->assertSessionHasErrors('yearly_payment_id');

    expect($transaction->fresh()->yearly_payment_id)->toBeNull();
});

test('February 29 repeats annually and falls on February 28 in nonleap years', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post(route('yearly_payments.store'), ['name' => 'Leap renewal', 'amount' => '10', 'currency' => 'PEN', 'due_month_day' => '02-29'])->assertRedirect();
    $this->travelTo(CarbonImmutable::parse('2027-02-01 12:00:00', 'America/Lima'));

    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page->where('plan.upcoming_commitments.0.next_due_on', '2027-02-28'));
    $this->travelTo(CarbonImmutable::parse('2028-02-01 12:00:00', 'America/Lima'));
    $this->get(route('yearly_payments.index'))->assertInertia(fn (Assert $page) => $page->where('plan.upcoming_commitments.0.next_due_on', '2028-02-29'));
});

test('upgrading existing due dates retains their month and day and all commitment values', function () {
    $owner = User::factory()->create();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create(['name' => 'Insurance', 'amount_minor' => 12345, 'due_month_day' => null]);
    $migration = require database_path('migrations/2026_10_16_020828_add_recurring_due_date_and_transaction_link_to_yearly_payments.php');
    $migration->down();
    DB::table('yearly_payments')->where('id', $payment->id)->update(['expected_due_on' => '2024-02-29']);

    $migration->up();

    $this->assertDatabaseHas('yearly_payments', ['id' => $payment->id, 'name' => 'Insurance', 'amount_minor' => 12345, 'due_month_day' => '02-29']);
});
