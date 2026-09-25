<?php

use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('search and inclusive amount and kind filters find an older match before pagination', function () {
    $owner = User::factory()->create();
    Transaction::factory()->count(51)->for($owner, 'owner')->spending()->create([
        'occurred_on' => '2026-08-20',
        'description' => 'Other merchant',
    ]);
    $match = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2025-01-02',
        'amount_minor' => 1250,
        'description' => 'Starbucks refund',
    ]);
    Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'occurred_on' => '2025-01-02',
        'amount_minor' => 1250,
        'description' => 'Starbucks income',
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', [
            'search' => 'STAR',
            'currency' => 'PEN',
            'amount_min' => '12.50',
            'amount_max' => '12.50',
            'kinds' => ['spending', 'refund'],
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('pagination.total', 1)
            ->where('pagination.per_page', 50)
            ->has('transactions', 1)
            ->where('transactions.0.id', $match->id));
});

test('the listing sends one ordered page and a count across all history', function () {
    $owner = User::factory()->create();
    $older = Transaction::factory()->for($owner, 'owner')->create(['occurred_on' => '2026-01-01']);
    $first = Transaction::factory()->for($owner, 'owner')->create(['occurred_on' => '2026-09-01']);
    $last = Transaction::factory()->for($owner, 'owner')->create(['occurred_on' => '2026-09-01']);
    Transaction::factory()->count(998)->for($owner, 'owner')->create(['occurred_on' => '2026-08-01']);

    $this->actingAs($owner);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 1001)
            ->where('pagination.current_page', 1)
            ->has('transactions', 50)
            ->where('transactions.0.id', $last->id)
            ->where('transactions.1.id', $first->id));

    $this->get(route('transactions.index', ['page' => 21]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 1001)
            ->has('transactions', 1)
            ->where('transactions.0.id', $older->id));

    $this->get(route('transactions.index', ['search' => '__unmatched__']))
        ->assertInertia(fn (Assert $page) => $page->where('pagination.total', 0));
});

test('search treats percent and underscore as literal description characters', function () {
    $owner = User::factory()->create();
    $literal = Transaction::factory()->for($owner, 'owner')->create(['description' => 'Shop 10%_off']);
    Transaction::factory()->for($owner, 'owner')->create(['description' => 'Shop 100 off']);

    $this->actingAs($owner)
        ->get(route('transactions.index', ['search' => '10%_']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 1)
            ->where('transactions.0.id', $literal->id));
});

test('exact lookup ignores list filters and includes a voided record in another currency', function () {
    $owner = User::factory()->create();
    $voided = Transaction::factory()->for($owner, 'owner')->usd()->create([
        'occurred_on' => '2020-01-01',
        'description' => 'Old voided purchase',
        'voided_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index', [
            'search' => 'unrelated',
            'currency' => 'PEN',
            'date_from' => '2026-01-01',
            'amount_min' => '200.00',
            'kinds' => ['income'],
            'selected' => $voided->id,
            'page' => 2,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 0)
            ->where('selected_transaction_id', $voided->id)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('selected_transaction.id', $voided->id)
                ->where('selected_transaction.description', 'Old voided purchase')
                ->where('selected_transaction.currency', 'USD')
                ->whereNot('selected_transaction.voided_at', null)));
});

test('exact lookup does not disclose another owner’s transaction', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $hidden = Transaction::factory()->for($other, 'owner')->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $hidden->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('selected_transaction', null)));
});

test('malformed and inverted filters are rejected', function (array $query, string $field) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('transactions.index', $query))
        ->assertSessionHasErrors($field);
})->with([
    'fractional precision' => [['amount_min' => '1.001'], 'amount_min'],
    'negative threshold' => [['amount_min' => '-1'], 'amount_min'],
    'inverted amounts' => [['currency' => 'PEN', 'amount_min' => '2.00', 'amount_max' => '1.99'], 'amount_max'],
    'amount without currency' => [['amount_min' => '1.00'], 'currency'],
    'inverted dates' => [['date_from' => '2026-09-02', 'date_to' => '2026-09-01'], 'date_to'],
    'unsupported kind' => [['kinds' => ['unknown']], 'kinds.0'],
    'invalid identifier' => [['selected' => 'oops'], 'selected'],
]);

test('unauthenticated listing redirects to login', function () {
    $this->get(route('transactions.index'))->assertRedirect(route('login'));
});

test('legacy Review Queue links open the owning Transaction in the current workspace', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->create();
    $lineItem = LineItem::factory()->for($breakdown)->create();
    $this->actingAs($owner);

    $this->get(route('review_queue.index', ['item' => 'transaction:'.$transaction->id]))
        ->assertRedirect(route('transactions.index', ['selected' => $transaction->id]));
    $this->get(route('review_queue.index', ['item' => 'line-item:'.$lineItem->id]))
        ->assertRedirect(route('transactions.index', ['selected' => $transaction->id]));
});

test('legacy Review Queue links do not expose another owner’s Transaction', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $hidden = Transaction::factory()->for($other, 'owner')->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index', ['item' => 'transaction:'.$hidden->id]))
        ->assertRedirect(route('transactions.index'));
});
