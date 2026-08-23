<?php

use App\Actions\Ledger\CountOutstandingReviews;
use App\Models\Category;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner records every supported money movement kind', function () {
    $owner = User::factory()->create();

    $movements = [
        [
            'kind' => 'spending',
            'direction' => 'debit',
            'description' => 'Mortgage payment',
        ],
        [
            'kind' => 'refund',
            'direction' => 'credit',
            'description' => 'Travel reimbursement',
        ],
        [
            'kind' => 'income',
            'direction' => 'credit',
            'income_source' => 'salary',
            'description' => 'Monthly salary',
        ],
        [
            'kind' => 'transfer',
            'direction' => 'debit',
            'transfer_purpose' => 'savings',
            'description' => 'Moved to savings',
        ],
    ];

    foreach ($movements as $movement) {
        $this->actingAs($owner)
            ->post(route('transactions.store'), [
                'occurred_on' => '2026-08-21',
                'amount' => '125.50',
                'currency' => 'PEN',
                ...$movement,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));
    }

    $this->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 4)
            ->where('transactions.0.kind', 'transfer')
            ->where('transactions.0.direction', 'debit')
            ->where('transactions.0.transfer_purpose', 'savings')
            ->where('transactions.1.kind', 'income')
            ->where('transactions.1.direction', 'credit')
            ->where('transactions.1.income_source', 'salary')
            ->where('transactions.2.kind', 'refund')
            ->where('transactions.3.kind', 'spending'));
});

test('period summaries keep movement kinds and currencies separate', function () {
    $this->travelTo('2026-08-21 12:00:00');
    $owner = User::factory()->create();
    $base = [
        'user_id' => $owner->id,
        'occurred_on' => '2026-08-21',
        'currency' => 'PEN',
    ];

    Transaction::factory()->create([...$base, 'kind' => 'spending', 'amount_minor' => 10_000]);
    Transaction::factory()->create([...$base, 'kind' => 'refund', 'direction' => 'credit', 'amount_minor' => 2_500]);
    Transaction::factory()->create([...$base, 'kind' => 'income', 'direction' => 'credit', 'income_source' => 'salary', 'amount_minor' => 70_000]);
    Transaction::factory()->create([...$base, 'kind' => 'transfer', 'transfer_purpose' => 'savings', 'amount_minor' => 12_000]);
    Transaction::factory()->create([...$base, 'kind' => 'transfer', 'direction' => 'credit', 'transfer_purpose' => 'savings', 'amount_minor' => 2_000]);
    Transaction::factory()->create([...$base, 'kind' => 'transfer', 'transfer_purpose' => 'card_payment', 'amount_minor' => 5_000]);
    Transaction::factory()->create([...$base, 'kind' => 'transfer', 'direction' => 'credit', 'transfer_purpose' => 'internal', 'amount_minor' => 8_000]);
    Transaction::factory()->create([...$base, 'kind' => 'income', 'direction' => 'credit', 'income_source' => 'other', 'amount_minor' => 99_000, 'voided_at' => now()]);
    Transaction::factory()->create([...$base, 'currency' => 'USD', 'kind' => 'spending', 'amount_minor' => 3_000]);

    $this->actingAs($owner)
        ->get(route('breakdown.index', [
            'currency' => 'PEN',
            'preset' => 'custom',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-21',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency', 'PEN')
            ->where('summary.net_spending_minor', '7500')
            ->where('summary.income_minor', '70000')
            ->where('summary.moved_to_savings_minor', '10000')
            ->missing('summary.net_external_cash_flow_minor'));

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary.summary.net_spending_minor', '7500')
            ->where('primary.summary.income_minor', '70000')
            ->where('primary.summary.moved_to_savings_minor', '10000')
            ->where('secondary.summary.net_spending_minor', '3000')
            ->where('secondary.summary.income_minor', '0')
            ->where('secondary.summary.moved_to_savings_minor', '0')
            ->missing('primary.summary.net_external_cash_flow_minor'));
});

test('the owner edits a Transaction kind and its matching details', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'kind' => 'spending',
        'direction' => 'debit',
        'description' => 'Client payment',
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $transaction), [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => 'income',
            'direction' => 'credit',
            'income_source' => 'independent_work',
            'description' => 'Client payment',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $deferred) => $deferred
                ->where('selected_transaction.kind', 'income')
                ->where('selected_transaction.direction', 'credit')
                ->where('selected_transaction.income_source', 'independent_work')
                ->where('selected_transaction.transfer_purpose', null)
                ->where('selected_transaction.category', null)));
});

test('Income and Transfers stay outside Spending Category review', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $income = Transaction::factory()->for($owner, 'owner')->income()->create();
    Transaction::factory()->for($owner, 'owner')->transfer()->create();

    expect(app(CountOutstandingReviews::class)->handle($owner))->toBe(0);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $income), [
            'category_id' => $category->id,
        ])
        ->assertSessionHasErrors('category_id');

    expect($income->refresh()->category_id)->toBeNull();
});

test('every persisted Statement Movement belongs to exactly one Transaction', function () {
    $movement = StatementMovement::factory()->create();

    expect($movement->transaction)->toBeInstanceOf(Transaction::class)
        ->and(fn () => DB::table('statement_movements')
            ->where('id', $movement->id)
            ->update(['transaction_id' => null]))
        ->toThrow(QueryException::class);
});

test('Movement Direction is required without an implicit default', function () {
    $directionColumn = collect(Schema::getColumns('transactions'))
        ->firstWhere('name', 'direction');

    expect($directionColumn['nullable'])->toBeFalse()
        ->and($directionColumn['default'])->toBeNull();
});
