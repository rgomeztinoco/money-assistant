<?php

use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can void a Transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 12345]);

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $transaction))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $this->assertModelExists($transaction);

    $this->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 0)
            ->has('voided_transactions', 1)
            ->where('voided_transactions.0.id', $transaction->id)
            ->where('voided_transactions.0.voided_at', fn (mixed $voidedAt) => is_string($voidedAt)),
        );

    expect($transaction->refresh()->voided_at)->not->toBeNull();
});

test('restoring the same Transaction returns exactly one contribution to the ledger', function (
    TransactionKind $kind,
    Currency $currency,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create([
            'amount_minor' => 12345,
            'kind' => $kind,
            'currency' => $currency,
        ]);

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $transaction))
        ->assertSessionHasNoErrors();

    $this->delete(route('transactions.void.destroy', $transaction))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id)
            ->has('voided_transactions', 0),
        );

    expect($transaction->refresh()->voided_at)->toBeNull();
})->with([
    'USD purchase' => [TransactionKind::Purchase, Currency::Usd],
    'PEN purchase' => [TransactionKind::Purchase, Currency::Pen],
    'USD Refund' => [TransactionKind::Refund, Currency::Usd],
    'PEN Refund' => [TransactionKind::Refund, Currency::Pen],
]);

test('void and restore reject operations that do not change current state', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $transaction))
        ->assertSessionHasNoErrors();

    $this->from(route('transactions.index'))
        ->post(route('transactions.void.store', $transaction))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('void_state');

    $this->delete(route('transactions.void.destroy', $transaction))
        ->assertSessionHasNoErrors();

    $this->from(route('transactions.index'))
        ->delete(route('transactions.void.destroy', $transaction))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('void_state');
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
