<?php

use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('debt forms record decimal balances, manual funding, and signed adjustments', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $page = visit('/debts')->resize(1280, 900)->screenshot(filename: 'debts-257-initial');
    $page->press('Add debt')->fill('#debt-name', 'Family loan')->fill('#debt-counterparty', 'Mother')->fill('#debt-opening', '100.01')->fill('#debt-opened_on', '2026-08-01')->press('Create debt')
        ->assertSee('Remaining balance')->assertSee('S/ 100.01')->assertSee('Active')
        ->press('Record operation')->select('#entry-kind', 'funding')->select('#entry-source', 'manual')->fill('#entry-amount', '20.02')->fill('#entry-date', '2026-08-02')->fill('#entry-detail', 'Additional borrowing')->screenshot(filename: 'debts-257-manual-form')->press('Save operation')
        ->assertSee('S/ 120.03')->assertSee('Funding')
        ->press('Record operation')->select('#entry-kind', 'adjustment')->fill('#entry-amount', '-0.03')->fill('#entry-date', '2026-08-03')->fill('#entry-detail', 'Corrected agreed balance')->press('Save operation')
        ->assertSee('S/ 120.00')->assertSee('Corrected agreed balance')->assertNoJavaScriptErrors()
        ->screenshot(filename: 'debts-257-history');
});

test('Transactions assigns a full imported payment and Breakdown can edit and explicitly unlink it', function () {
    $owner = User::factory()->create();
    $debt = Debt::create(['user_id' => $owner->id, 'name' => 'Family loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 10000, 'opened_on' => '2026-08-01']);
    $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-08-05', 'amount_minor' => 2501, 'description' => 'Imported repayment']);
    $this->actingAs($owner);
    $page = visit('/transactions')->resize(1280, 900);
    $page->click('[data-test="transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Record as debt payment")')->select('#transaction-debt', (string) $debt->id)->press('Save Transaction')->assertSee('Transaction updated.')->assertSee('Debt')->assertNoJavaScriptErrors();
    visit('/debts/'.$debt->id)->assertSee('S/ 74.99')->screenshot(filename: 'debts-257-repayment');
    $page = visit('/breakdown?period=custom&date_from=2026-08-01&date_to=2026-08-31');
    $page->click('[data-test="breakdown-transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')->assertSelected('#transaction-debt', (string) $debt->id)->fill('#transaction-description', 'Corrected repayment description')->press('Save Transaction')->assertSee('Transaction updated.')
        ->click('[data-test="breakdown-transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')->select('#transaction-kind', 'spending')->check('input[name="unlink_debt"]')->press('Save Transaction')->assertNoJavaScriptErrors();
    visit('/debts/'.$debt->id)->assertSee('S/ 100.00')->assertSee('No operations recorded');
});
