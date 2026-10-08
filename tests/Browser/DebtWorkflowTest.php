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
        ->assertSee('Remaining balance')->assertSee('S/ 100.01')->assertSee('Active')->assertMissing('[role="dialog"]')
        ->click('header button:has-text("Record operation")')->assertVisible('#entry-kind')->select('#entry-kind', 'funding')->select('#entry-source', 'manual')->fill('#entry-amount', '20.02')->fill('#entry-date', '2026-08-02')->fill('#entry-detail', 'Additional borrowing')->screenshot(filename: 'debts-257-manual-form')->click('[role="dialog"] button[type="submit"]')
        ->assertSee('S/ 120.03')->assertSee('Funding')->assertMissing('[role="dialog"]')->screenshot(filename: 'debts-257-funded')
        ->click('header button:has-text("Record operation")')->assertVisible('#entry-kind')->screenshot(filename: 'debts-257-adjustment-open')->select('#entry-kind', 'adjustment')->assertSeeIn('[role="dialog"]', 'Reason')->screenshot(filename: 'debts-257-adjustment-selected')->fill('#entry-amount', '-0.03')->fill('#entry-date', '2026-08-03')->fill('#entry-detail', 'Corrected agreed balance')->screenshot(filename: 'debts-257-adjustment-form')->click('[role="dialog"] button[type="submit"]')
        ->assertSee('S/ 120.00')->assertSee('Corrected agreed balance')->assertNoJavaScriptErrors()
        ->screenshot(filename: 'debts-257-history');
});

test('Transactions assigns a full imported payment and Breakdown can edit and explicitly unlink it', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['name' => 'Family loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 10000, 'opened_on' => '2026-08-01']);
    $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-08-05', 'amount_minor' => 2501, 'description' => 'Imported repayment']);
    $this->actingAs($owner);
    $page = visit('/transactions')->resize(1280, 900);
    $page->click('[data-test="transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Record as debt payment")')->select('#transaction-debt', (string) $debt->id)->press('Save Transaction')->assertSee('Transaction updated.')->assertSee('Debt')->assertNoJavaScriptErrors();
    visit('/debts/'.$debt->id)->assertSee('S/ 74.99')->screenshot(filename: 'debts-257-repayment');
    $page = visit('/breakdown?period=custom&date_from=2026-08-01&date_to=2026-08-31');
    $page->click('[data-test="breakdown-transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')->assertSelected('#transaction-debt', (string) $debt->id)->fill('#transaction-description', 'Corrected repayment description')->press('Save Transaction')->assertSee('Transaction updated.')
        ->click('[data-test="breakdown-transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')->select('#transaction-kind', 'spending')->check('#transaction-unlink-debt')->press('Save Transaction')->assertNoJavaScriptErrors();
    visit('/debts/'.$debt->id)->assertSee('S/ 100.00')->assertSee('No operations recorded');
});

test('owner confirms interest on an existing payment and plans full monthly repayments', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['name' => 'Bank loan', 'counterparty' => 'Bank', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 100000, 'opened_on' => '2026-08-01']);
    $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-08-05', 'amount_minor' => 20000, 'description' => 'Imported bank payment']);
    $this->actingAs($owner);
    $page = visit('/debts/'.$debt->id)->resize(1280, 900);
    $page->press('Edit debt')->fill('#debt-target', '200.00')->click('[role="dialog"] button[type="submit"]')->assertMissing('[role="dialog"]')->assertSee('S/ 200.00 target')
        ->click('header button:has-text("Record operation")')->assertVisible('#entry-kind')->select('#entry-kind', 'interest_charge')->fill('#entry-amount', '30.00')->fill('#entry-date', '2026-08-04')->fill('#entry-detail', 'Confirmed bank interest')->screenshot(filename: 'debts-258-charge-form')->click('[role="dialog"] button[type="submit"]')->assertMissing('[role="dialog"]')->assertSee('S/ 1,030.00')->assertSee('Interest charge');
    $page->click('header button:has-text("Record operation")')->assertVisible('#entry-kind')->select('#entry-transaction', (string) $payment->id)->fill('#debt-principal', '169.99')->fill('#debt-interest', '30.00')->click('[role="dialog"] button[type="submit"]')->assertSee('Principal plus interest must equal the full posted amount.')
        ->fill('#debt-principal', '170.00')->screenshot(filename: 'debts-258-payment-form')->click('[role="dialog"] button[type="submit"]')->assertMissing('[role="dialog"]')->assertSee('S/ 830.00')->assertSee('Principal S/ 170.00')->assertSee('Interest S/ 30.00')->assertNoJavaScriptErrors()->screenshot(filename: 'debts-258-history');
    visit('/?period=custom&date_from=2026-08-01&date_to=2026-08-31')->resize(1280, 900)->assertSee('Current outstanding balances.')->assertSee('Outgoing monthly target')->assertSee('S/ 830.00')->assertNoJavaScriptErrors()->screenshot(filename: 'debts-258-home');
});
