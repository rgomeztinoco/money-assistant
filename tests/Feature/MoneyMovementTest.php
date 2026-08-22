<?php

use App\Actions\Ledger\CountOutstandingReviews;
use App\Models\Category;
use App\Models\SpendingNotificationReference;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner records every supported money movement meaning', function () {
    $owner = User::factory()->create();

    $movements = [
        [
            'kind' => 'spending',
            'direction' => 'debit',
            'merchant_description' => 'Mortgage payment',
        ],
        [
            'kind' => 'refund',
            'direction' => 'credit',
            'merchant_description' => 'Travel reimbursement',
        ],
        [
            'kind' => 'income',
            'direction' => 'credit',
            'income_source' => 'salary',
            'merchant_description' => 'Monthly salary',
        ],
        [
            'kind' => 'transfer',
            'direction' => 'debit',
            'transfer_purpose' => 'savings',
            'merchant_description' => 'Moved to savings',
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

test('period summaries keep movement meanings and currencies separate', function () {
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
        ->get(route('reports.show', [
            'currency' => 'PEN',
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

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summaries.PEN.net_spending_minor', '7500')
            ->where('summaries.PEN.income_minor', '70000')
            ->where('summaries.PEN.moved_to_savings_minor', '10000')
            ->where('summaries.USD.net_spending_minor', '3000')
            ->where('summaries.USD.income_minor', '0')
            ->where('summaries.USD.moved_to_savings_minor', '0')
            ->missing('summaries.PEN.net_external_cash_flow_minor'));
});

test('the owner edits a Transaction financial meaning and its matching details', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'kind' => 'spending',
        'direction' => 'debit',
        'merchant_description' => 'Client payment',
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $transaction), [
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => 'income',
            'direction' => 'credit',
            'income_source' => 'independent_work',
            'merchant_description' => 'Client payment',
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

test('the money movement migration preserves legacy records and backfills statement Transactions', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $purchase = Transaction::factory()->for($owner, 'owner')->create([
        'kind' => 'spending',
        'amount_minor' => 45_678,
        'currency' => 'USD',
        'category_id' => $category->id,
        'voided_at' => now(),
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->create([
        'kind' => 'refund',
        'amount_minor' => 1_234,
        'currency' => 'USD',
        'original_purchase_id' => $purchase->id,
    ]);
    $reference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($purchase)
        ->create();
    $statementImport = StatementImport::factory()->for($owner, 'owner')->create();
    $purchaseMovement = StatementMovement::factory()->for($statementImport)->create([
        'transaction_id' => $purchase->id,
        'position' => 1,
        'amount_minor' => 45_678,
        'currency' => 'USD',
        'direction' => 'debit',
        'classification' => 'purchase',
        'source_metadata' => ['source' => 'legacy-pdf'],
    ]);
    $incomeMovement = StatementMovement::factory()->for($statementImport)->create([
        'transaction_id' => null,
        'position' => 2,
        'amount_minor' => 80_000,
        'currency' => 'PEN',
        'direction' => 'credit',
        'classification' => 'income',
        'description' => 'Legacy salary',
        'source_metadata' => ['source' => 'legacy-pdf'],
    ]);
    $alreadyRecordedMovement = StatementMovement::factory()->for($statementImport)->create([
        'transaction_id' => null,
        'position' => 3,
        'classification' => 'already_recorded',
        'description' => 'Legacy movement represented elsewhere',
    ]);
    DB::table('transactions')->where('id', $purchase->id)->update(['kind' => 'purchase']);

    $migrationPath = collect(glob(database_path('migrations/*_migrate_existing_money_movements.php')))->sole();
    $migration = require $migrationPath;
    $migration->up();

    expect($purchase->refresh()->kind->value)->toBe('spending')
        ->and($purchase->direction->value)->toBe('debit')
        ->and($purchase->amount_minor)->toBe(45_678)
        ->and($purchase->currency->value)->toBe('USD')
        ->and($purchase->category_id)->toBe($category->id)
        ->and($purchase->voided_at)->not->toBeNull()
        ->and($refund->refresh()->original_purchase_id)->toBe($purchase->id)
        ->and($reference->refresh()->transaction_id)->toBe($purchase->id)
        ->and($purchaseMovement->refresh()->source_metadata)->toBe(['source' => 'legacy-pdf'])
        ->and($incomeMovement->refresh()->transaction)->not->toBeNull()
        ->and($incomeMovement->transaction->kind->value)->toBe('income')
        ->and($incomeMovement->transaction->direction->value)->toBe('credit')
        ->and($incomeMovement->transaction->income_source->value)->toBe('other')
        ->and($incomeMovement->transaction->amount_minor)->toBe(80_000)
        ->and($incomeMovement->transaction->currency->value)->toBe('PEN')
        ->and($incomeMovement->source_metadata)->toBe(['source' => 'legacy-pdf'])
        ->and($alreadyRecordedMovement->refresh()->transaction_id)->toBeNull();
});

test('money movement migrations downgrade unsupported manual meanings by direction', function () {
    $semanticMigrationPath = collect(glob(database_path('migrations/*_migrate_existing_money_movements.php')))->sole();
    $fieldsMigrationPath = collect(glob(database_path('migrations/*_add_money_movement_fields_to_transactions_table.php')))->sole();
    $semanticMigration = require $semanticMigrationPath;
    $fieldsMigration = require $fieldsMigrationPath;

    $semanticMigration->up();
    $income = Transaction::factory()->income()->create();
    $transfer = Transaction::factory()->transfer()->create();

    $semanticMigration->down();

    expect(DB::table('transactions')->where('id', $income->id)->value('kind'))->toBe('refund')
        ->and(DB::table('transactions')->where('id', $transfer->id)->value('kind'))->toBe('purchase');

    $fieldsMigration->down();

    expect(Schema::hasColumns('transactions', ['direction', 'income_source', 'transfer_purpose']))
        ->toBeFalse();
});
