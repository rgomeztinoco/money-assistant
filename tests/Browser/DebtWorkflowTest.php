<?php

use App\Models\Debt;
use App\Models\DebtEntry;
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
    $page->click('[data-test="breakdown-transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')->assertSelected('#transaction-debt', (string) $debt->id)->fill('#transaction-description', 'Corrected repayment description')->press('Save Transaction')->assertSee('Transaction updated.')->assertMissing('[role="dialog"]')
        ->click('[data-test="breakdown-transaction-'.$payment->id.'"]')->click('[data-slot="dropdown-menu-item"]:has-text("Edit")')->select('#transaction-kind', 'spending')->check('#transaction-unlink-debt')->press('Save Transaction')->assertMissing('[role="dialog"]')->assertNoJavaScriptErrors();
    visit('/debts/'.$debt->id)->assertSee('S/ 100.00')->assertSee('No operations recorded');
});

test('debt operations search older Transactions and edit the same payment and adjustment in place', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['name' => 'Family loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 10000, 'opened_on' => '2026-01-01']);
    $old = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-01-02', 'description' => 'Historical family repayment', 'amount_minor' => 2501]);
    $recent = Transaction::factory()->count(101)->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-08-01', 'description' => 'Recent unrelated payment']);
    $funding = Transaction::factory()->for($owner, 'owner')->income()->pen()->create(['occurred_on' => '2026-08-02', 'description' => 'Latest borrowing']);
    $this->actingAs($owner);

    $page = visit('/debts/'.$debt->id)->resize(1440, 900);
    $page->assertSeeIn('header [aria-label="breadcrumb"]', 'Debts')->assertSeeIn('header [aria-label="breadcrumb"]', 'Family loan')
        ->click('header button:has-text("Record operation")')->assertVisible('#entry-kind')->click('#entry-transaction')->screenshot(filename: 'debt-search-initial');
    $page->click('[cmdk-item]:has-text("Load more Transactions")')->assertVisible('[data-test="debt-transaction-'.$recent[1]->id.'"]');
    $page->keys('[aria-label="Search Transactions"]', 'Escape')->screenshot(filename: 'debt-search-after-escape')->assertVisible('#entry-kind');
    $page->select('#entry-kind', 'funding');
    $page->click('#entry-transaction')->assertVisible('[data-test="debt-transaction-'.$funding->id.'"]')->assertMissing('[data-test="debt-transaction-'.$recent[1]->id.'"]');
    $page->keys('[aria-label="Search Transactions"]', 'Escape')->select('#entry-kind', 'repayment')->click('#entry-transaction')->fill('[aria-label="Search Transactions"]', 'Historical family')
        ->click('[data-test="debt-transaction-'.$old->id.'"]');
    $page->press('Save operation')->assertMissing('[role="dialog"]')->assertSee('S/ 74.99');
    $page->click('[aria-label="Edit Historical family repayment"]')->assertVisible('#entry-amount')->fill('#entry-amount', '30.02')->fill('#entry-date', '2026-01-03')->fill('#entry-detail', 'Corrected historical payment')->press('Save changes')
        ->assertMissing('[role="dialog"]')->assertSee('S/ 69.98')->assertSee('Corrected historical payment')->assertNoJavaScriptErrors()->screenshot(filename: 'debts-reviewed-detail');
    $this->assertDatabaseCount('transactions', 103);
    $this->assertDatabaseHas('transactions', ['id' => $old->id, 'amount_minor' => 3002, 'description' => 'Corrected historical payment']);

    $page->click('header button:has-text("Record operation")')->assertVisible('#entry-kind')->select('#entry-kind', 'adjustment')->fill('#entry-amount', '-0.03')->fill('#entry-date', '2026-01-04')->fill('#entry-detail', 'Agreed correction')->press('Save operation')->assertMissing('[role="dialog"]')
        ->click('[aria-label="Edit Agreed correction"]')->assertVisible('#entry-amount')->fill('#entry-amount', '-0.02')->fill('#entry-detail', 'Revised correction')->press('Save changes')->assertMissing('[role="dialog"]')->assertSee('S/ 69.96')->assertSee('Revised correction')->assertNoJavaScriptErrors();
});

test('debt summaries keep currencies separate while tables filter and scroll within the desktop workspace', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['name' => 'Family loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 10001, 'opened_on' => '2026-01-01']);
    Debt::factory()->for($owner, 'owner')->create(['name' => 'USD receivable', 'direction' => 'receivable', 'currency' => 'USD', 'opening_balance_minor' => 2502]);
    Debt::factory()->for($owner, 'owner')->create(['name' => 'Settled loan', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 0]);
    Debt::factory()->count(30)->for($owner, 'owner')->create(['direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 1]);
    DebtEntry::factory()->count(40)->for($debt)->create(['kind' => 'adjustment', 'transaction_id' => null, 'amount_minor' => 1, 'occurred_on' => '2026-01-02']);
    $this->actingAs($owner);

    $page = visit('/debts')->resize(1440, 900);
    $page->assertSeeIn('[data-test="owed-PEN"]', 'S/ 100.71')->assertSeeIn('[data-test="receivable-USD"]', '$ 25.02')->assertDontSee('Settled loan')
        ->assertScript('() => document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('() => { const table = document.querySelector(\'[data-test="debt-list"]\'); return table.scrollHeight > table.clientHeight; }')
        ->fill('[aria-label="Search debts"]', 'family')->assertSee('Family loan')->assertDontSee('USD receivable')->fill('[aria-label="Search debts"]', '')
        ->click('[role="tab"]:has-text("Settled")')->assertSee('Settled loan')->assertDontSee('Family loan')->click('[role="tab"]:has-text("Active")')->screenshot(filename: 'debts-reviewed-index')
        ->click('a:has-text("Family loan")')->assertSee('History')->assertScript('() => document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('() => { const table = document.querySelector(\'[data-test="debt-history"]\'); return table.scrollHeight > table.clientHeight; }')->assertNoJavaScriptErrors()->screenshot(filename: 'debts-reviewed-history-scroll');
    $page->resize(390, 844)->assertSee('Family loan')->assertVisible('header button:has-text("Record operation")')->assertNoJavaScriptErrors()->screenshot(filename: 'debts-reviewed-mobile');
});
