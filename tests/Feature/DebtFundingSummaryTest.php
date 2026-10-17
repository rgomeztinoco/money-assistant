<?php

use App\Models\Debt;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('Breakdown reports borrowing and lending separately from income spending and repayments', function (string $direction, string $currency, string $key) {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['direction' => $direction, 'currency' => $currency, 'opening_balance_minor' => 0, 'opened_on' => '2026-10-01']);
    $foreign = Debt::factory()->create(['currency' => $currency, 'opening_balance_minor' => 0, 'opened_on' => '2026-10-01']);
    $this->actingAs($foreign->owner)->post('/debts/'.$foreign->id.'/entries', ['kind' => 'funding', 'amount' => '99.00', 'occurred_on' => '2026-10-16', 'description' => 'Other owner borrowing'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/debts/'.$debt->id.'/entries', ['kind' => 'funding', 'amount' => '1500.01', 'occurred_on' => '2026-10-16', 'description' => 'Posted loan funding'])->assertSessionHasNoErrors();
    $transaction = $debt->entries()->sole()->transaction;

    $this->get('/breakdown?period=custom&date_from=2026-10-01&date_to=2026-10-31')->assertInertia(fn (Assert $page) => $page
        ->where('summary.'.$currency.'.'.$key, '150001')
        ->where('summary.'.$currency.'.'.($key === 'borrowed_minor' ? 'lent_minor' : 'borrowed_minor'), '0')
        ->where('summary.'.$currency.'.net_spending_minor', '0')->where('summary.'.$currency.'.income_minor', '0')
        ->where('summary.'.$currency.'.debt_payments_made_minor', '0')->where('summary.'.$currency.'.debt_payments_received_minor', '0')
        ->where('summary.'.($currency === 'PEN' ? 'USD' : 'PEN').'.'.$key, '0'));
    $this->get('/breakdown?period=custom&date_from=2026-09-01&date_to=2026-09-30')->assertInertia(fn (Assert $page) => $page->where('summary.'.$currency.'.'.$key, '0'));

    $this->post(route('transactions.void.store', $transaction))->assertSessionHasNoErrors();
    $this->get('/breakdown?period=custom&date_from=2026-10-01&date_to=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('summary.'.$currency.'.'.$key, '0'));
})->with([
    'PEN borrowed' => ['owed', 'PEN', 'borrowed_minor'],
    'USD lent' => ['receivable', 'USD', 'lent_minor'],
]);
