<?php

use App\Models\Debt;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the debt index edits in place and settles through a non-cash adjustment', function () {
    $owner = User::factory()->create();
    $debt = Debt::factory()->for($owner, 'owner')->create(['name' => 'Family loan', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => 10001]);
    $this->actingAs($owner);

    $page = visit('/debts')->resize(1280, 900);
    $page->click('[aria-label="Actions for Family loan"]')->click('[role="menuitem"]:has-text("Edit")')
        ->fill('#debt-name', 'Renamed loan')->press('Save debt')->assertMissing('[role="dialog"]')
        ->assertPathIs('/debts')->assertSee('Renamed loan')
        ->click('[aria-label="Actions for Renamed loan"]')->click('[role="menuitem"]:has-text("Settle debt")')
        ->assertSeeIn('[role="alertdialog"]', 'S/ 100.01')->click('[role="alertdialog"] button:has-text("Cancel")')->assertMissing('[role="alertdialog"]');
    $this->assertDatabaseCount('debt_entries', 0);

    $page->click('[aria-label="Actions for Renamed loan"]')->click('[role="menuitem"]:has-text("Settle debt")')
        ->click('[role="alertdialog"] button:has-text("Settle debt")')->assertMissing('[role="alertdialog"]')
        ->assertPathIs('/debts')->assertDontSee('Renamed loan')->click('[role="tab"]:has-text("Settled")')
        ->assertSee('Renamed loan')->assertSee('S/ 0.00')->assertNoJavaScriptErrors()->screenshot(filename: 'debts-index-actions');

    $this->assertDatabaseHas('debts', ['id' => $debt->id, 'name' => 'Renamed loan']);
    $this->assertDatabaseHas('debt_entries', ['debt_id' => $debt->id, 'kind' => 'adjustment', 'amount_minor' => -10001, 'transaction_id' => null]);
    $this->assertDatabaseCount('transactions', 0);
});
