<?php

use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('settling a debt records the exact remaining balance as a non-cash adjustment once', function (string $direction) {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 15:00:00', config('app.reporting_timezone')));
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['direction' => $direction, 'opening_balance_minor' => 10001, 'opened_on' => '2026-10-01']);
    $payment = Transaction::factory()->for($owner, 'owner')->pen()->create(['kind' => 'debt', 'direction' => $direction === 'owed' ? 'debit' : 'credit', 'amount_minor' => 2501, 'occurred_on' => '2026-10-15']);
    DebtEntry::factory()->for($debt)->for($payment)->create(['kind' => 'repayment', 'amount_minor' => 2501, 'occurred_on' => '2026-10-15']);

    $this->actingAs($owner)->post('/debts/'.$debt->id.'/settle', ['amount_minor' => '-1'])->assertSessionHasNoErrors()->assertRedirect(route('debts.index'));

    $this->assertDatabaseHas('debt_entries', ['debt_id' => $debt->id, 'kind' => 'adjustment', 'amount_minor' => -7500, 'transaction_id' => null, 'occurred_on' => '2026-10-16', 'reason' => 'Debt settled by adjustment']);
    $this->assertDatabaseCount('transactions', 1);
    $this->get('/debts')->assertInertia(fn (Assert $page) => $page->where('debts.0.balance_minor', '0')->where('debts.0.status', 'settled'));
    $this->post('/debts/'.$debt->id.'/settle')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('debt_entries', 2);
})->with(['owed', 'receivable']);

test('only the owner can settle a debt', function () {
    $debt = Debt::factory()->create();

    $this->post('/debts/'.$debt->id.'/settle')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->post('/debts/'.$debt->id.'/settle')->assertNotFound();

    $this->assertDatabaseCount('debt_entries', 0);
    $this->assertDatabaseCount('transactions', 0);
});

test('a debt cannot be settled before its opening date', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['opened_on' => now(config('app.reporting_timezone'))->addDay()->toDateString()]);

    $this->actingAs($owner)->post('/debts/'.$debt->id.'/settle')->assertSessionHasErrors('settlement');

    $this->assertDatabaseCount('debt_entries', 0);
});

test('editing a debt from the index returns to the index', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->from('/debts')->put('/debts/'.$debt->id, [
        'name' => 'Renamed debt', 'counterparty' => $debt->counterparty, 'direction' => $debt->direction->value,
        'currency' => $debt->currency->value, 'opening_balance_minor' => '10000', 'opened_on' => $debt->opened_on->toDateString(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('debts.index'));

    $this->assertDatabaseHas('debts', ['id' => $debt->id, 'name' => 'Renamed debt']);
});

test('settlement rejects a balance larger than a single adjustment can store', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 03:00:00', 'UTC'));
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['opening_balance_minor' => PHP_INT_MAX, 'opened_on' => '2026-10-16']);
    DebtEntry::factory()->for($debt)->create(['kind' => 'adjustment', 'amount_minor' => 1]);

    $this->actingAs($owner)->post('/debts/'.$debt->id.'/settle')->assertSessionHasErrors(['settlement' => 'This balance is too large for one adjustment. Reduce it with smaller adjustments first.']);

    $this->assertDatabaseCount('debt_entries', 1);
    $this->assertDatabaseCount('transactions', 0);
});
