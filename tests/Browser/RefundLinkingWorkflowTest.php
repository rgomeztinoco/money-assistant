<?php

use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner links a Refund and sees an excessive relationship in the Review Queue', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 10_000,
            'description' => 'Original spending',
        ]);
    Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 12_000,
            'description' => 'Store Refund',
        ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->press('Inspect')
        ->select('Edit original Spending Transaction', (string) $spending->id)
        ->press('Save Transaction')
        ->assertSee('Transaction updated.');

    visit('/review-queue')
        ->assertSee('Linked Refunds exceed the spending')
        ->assertSee('Review the linked spending and correct the Refund relationship before continuing.')
        ->click('Correct this relationship')
        ->assertSee('Edit current Transaction')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
