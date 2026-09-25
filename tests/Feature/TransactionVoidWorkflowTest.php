<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('Voided Transactions stay in the database and can be found by exact ID', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => now()->toDateString(),
        'amount_minor' => 12345,
        'voided_at' => now(),
    ]);
    $this->actingAs($owner);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 0)
            ->has('transactions', 0));

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.id', $transaction->id)
                ->whereNot('selected_transaction.voided_at', null)));

    $this->get(route('breakdown.index', [
        'currency' => 'USD',
        'period' => 'custom',
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('summary.USD.net_spending_minor', '0'));

    $this->assertModelExists($transaction);
});

test('retired void and restore endpoints leave retained Voided state intact', function () {
    $owner = User::factory()->create();
    $active = Transaction::factory()->for($owner, 'owner')->create();
    $voided = Transaction::factory()->for($owner, 'owner')->create(['voided_at' => now()]);
    $this->actingAs($owner);

    $this->post('/transactions/'.$active->id.'/void')->assertNotFound();
    $this->delete('/transactions/'.$voided->id.'/void')->assertNotFound();

    expect($active->refresh()->voided_at)->toBeNull()
        ->and($voided->refresh()->voided_at)->not->toBeNull();
});

test('the Transaction table exposes portable ledger indexes', function () {
    $indexes = collect(Schema::getIndexes('transactions'))->keyBy('name');

    expect($indexes)->toHaveKeys([
        'transactions_user_id_occurred_on_id_index',
        'transactions_ledger_state_index',
    ])
        ->and($indexes['transactions_user_id_occurred_on_id_index']['columns'])
        ->toBe(['user_id', 'occurred_on', 'id'])
        ->and($indexes['transactions_ledger_state_index']['columns'])
        ->toBe(['user_id', 'voided_at', 'occurred_on', 'id']);
});
