<?php

use App\Actions\Ledger\ChangeTransactionVoidState;
use App\Currency;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\Transaction;
use App\Models\TransactionStateChange;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionKind;
use App\TransactionVoidOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ConcurrentVoidCommand;

test('the owner can void a Transaction without deleting its identity or financial effect lingering', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 12345]);

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => '87fd7fb0-c1bb-4ae0-bc30-5bfb7e42383a',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $this->assertModelExists($transaction);

    $this->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 0)
            ->has('voided_transactions', 1)
            ->where('voided_transactions.0.id', $transaction->id)
            ->where('voided_transactions.0.revision', 2)
            ->where('voided_transactions.0.voided_at', fn (mixed $voidedAt) => is_string($voidedAt)),
        );

    expect($transaction->refresh()->voided_at)->not->toBeNull()
        ->and($transaction->revision)->toBe(2);
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
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => '05c04696-712a-4556-9ca7-17f706ddb667',
        ])
        ->assertSessionHasNoErrors();

    $this->delete(route('transactions.void.destroy', $transaction), [
        'expected_revision' => 2,
        'idempotency_key' => 'b65ebded-20fa-4800-93f0-f3aebd3d6097',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id)
            ->where('transactions.0.revision', 3)
            ->has('voided_transactions', 0),
        );

    expect($transaction->refresh()->voided_at)->toBeNull()
        ->and($transaction->revision)->toBe(3)
        ->and(TransactionStateChange::query()->count())->toBe(2);

    $voidOutcome = TransactionStateChange::query()
        ->where('operation', TransactionVoidOperation::Void)
        ->firstOrFail();
    $restoreOutcome = TransactionStateChange::query()
        ->where('operation', TransactionVoidOperation::Restore)
        ->firstOrFail();

    expect($voidOutcome->expected_revision)->toBe(1)
        ->and($voidOutcome->result_revision)->toBe(2)
        ->and($voidOutcome->result_voided_at)->not->toBeNull()
        ->and($restoreOutcome->expected_revision)->toBe(2)
        ->and($restoreOutcome->result_revision)->toBe(3)
        ->and($restoreOutcome->result_voided_at)->toBeNull();
})->with([
    'USD purchase' => [TransactionKind::Purchase, Currency::Usd],
    'PEN purchase' => [TransactionKind::Purchase, Currency::Pen],
    'USD refund' => [TransactionKind::Refund, Currency::Usd],
    'PEN refund' => [TransactionKind::Refund, Currency::Pen],
]);

test('repeated identical void and restore commands do not cause another state transition', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 12345]);
    $voidPayload = [
        'expected_revision' => 1,
        'idempotency_key' => '9e810ed9-255b-463f-9f49-d134cad44022',
    ];
    $restorePayload = [
        'expected_revision' => 2,
        'idempotency_key' => '0a00da11-8e9a-4689-aa0c-76f8c5f45e91',
    ];

    $this->actingAs($owner);

    foreach (range(1, 2) as $attempt) {
        $this->post(route('transactions.void.store', $transaction), $voidPayload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));
    }

    expect($transaction->refresh()->revision)->toBe(2)
        ->and($transaction->voided_at)->not->toBeNull();

    foreach (range(1, 2) as $attempt) {
        $this->delete(route('transactions.void.destroy', $transaction), $restorePayload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));
    }

    expect($transaction->refresh()->revision)->toBe(3)
        ->and($transaction->voided_at)->toBeNull();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 1)
            ->has('voided_transactions', 0),
        );
});

test('an identical retry returns its original outcome after later state changes', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 12345]);
    $changeTransactionVoidState = app(ChangeTransactionVoidState::class);

    $originalOutcome = $changeTransactionVoidState->handle(
        owner: $owner,
        transaction: $transaction,
        operation: TransactionVoidOperation::Void,
        expectedRevision: 1,
        idempotencyKey: 'b9417113-b607-4354-9a82-13245b209ad9',
    );

    $changeTransactionVoidState->handle(
        owner: $owner,
        transaction: $transaction,
        operation: TransactionVoidOperation::Restore,
        expectedRevision: 2,
        idempotencyKey: 'fda7bd9a-4583-4afe-b89d-80254eaed0b8',
    );

    $retriedOutcome = $changeTransactionVoidState->handle(
        owner: $owner,
        transaction: $transaction,
        operation: TransactionVoidOperation::Void,
        expectedRevision: 1,
        idempotencyKey: 'b9417113-b607-4354-9a82-13245b209ad9',
    );

    expect($retriedOutcome->is($originalOutcome))->toBeTrue()
        ->and($retriedOutcome->result_revision)->toBe(2)
        ->and($retriedOutcome->result_voided_at?->toIso8601String())
        ->toBe($originalOutcome->result_voided_at?->toIso8601String())
        ->and(TransactionStateChange::query()->count())->toBe(2)
        ->and($transaction->refresh()->revision)->toBe(3)
        ->and($transaction->voided_at)->toBeNull();
});

test('changed reuse of an idempotency key is rejected without changing the Transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create();
    $idempotencyKey = '9101f0f8-9c84-4b39-b96c-bc321028ff54';

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => $idempotencyKey,
        ])
        ->assertSessionHasNoErrors();

    expect(fn () => app(ChangeTransactionVoidState::class)->handle(
        owner: $owner,
        transaction: $transaction,
        operation: TransactionVoidOperation::Void,
        expectedRevision: 2,
        idempotencyKey: $idempotencyKey,
    ))->toThrow(IdempotencyKeyConflict::class);

    $this->from(route('transactions.index'))
        ->delete(route('transactions.void.destroy', $transaction), [
            'expected_revision' => 2,
            'idempotency_key' => $idempotencyKey,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors([
            'idempotency_key' => 'This idempotency key was already used for a different Transaction command.',
        ]);

    expect($transaction->refresh()->revision)->toBe(2)
        ->and($transaction->voided_at)->not->toBeNull()
        ->and(TransactionStateChange::query()->count())->toBe(1);
});

test('competing void commands serialize so one stale writer cannot overwrite the winner', function () {
    $fixtureConnectionName = 'concurrent_void_fixture';
    config([
        "database.connections.{$fixtureConnectionName}" => config('database.connections.pgsql'),
    ]);

    $fixtureConnection = DB::connection($fixtureConnectionName);
    $now = now();
    $ownerId = $fixtureConnection->table('users')->insertGetId([
        'name' => 'Concurrent Owner',
        'email' => Str::uuid().'@example.test',
        'password' => 'not-used',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transactionId = $fixtureConnection->table('transactions')->insertGetId([
        'user_id' => $ownerId,
        'occurred_on' => $now->toDateString(),
        'amount_minor' => 12345,
        'currency' => Currency::Usd->value,
        'kind' => TransactionKind::Purchase->value,
        'merchant_description' => 'Concurrent merchant',
        'confirmed_at' => $now,
        'revision' => 1,
        'provisional_fields' => '[]',
        'voided_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    try {
        $results = Concurrency::run([
            ConcurrentVoidCommand::make(
                $ownerId,
                $transactionId,
                '51512fcb-e88a-4ec3-9952-b181c71ec488',
            ),
            ConcurrentVoidCommand::make(
                $ownerId,
                $transactionId,
                'a746392e-5048-41b6-ae38-f7c0c1001aa6',
            ),
        ]);

        expect(collect($results)->pluck('status')->sort()->values()->all())
            ->toBe(['changed', 'stale'])
            ->and(collect($results)->pluck('revision')->all())->toBe([2, 2])
            ->and(
                $fixtureConnection->table('transactions')
                    ->where('id', $transactionId)
                    ->value('revision'),
            )->toBe(2)
            ->and(
                $fixtureConnection->table('transaction_state_changes')
                    ->where('transaction_id', $transactionId)
                    ->count(),
            )->toBe(1);
    } finally {
        $fixtureConnection->table('users')->where('id', $ownerId)->delete();
        DB::purge($fixtureConnectionName);
    }
});

test('Transaction state outcome factory creates records that satisfy database invariants', function () {
    $stateChange = TransactionStateChange::factory()->create();

    expect($stateChange->owner->is($stateChange->transaction->owner))->toBeTrue()
        ->and($stateChange->operation)->toBe(TransactionVoidOperation::Void)
        ->and($stateChange->result_revision)->toBe($stateChange->expected_revision + 1)
        ->and($stateChange->result_voided_at)->not->toBeNull();
});

test('PostgreSQL rejects invalid Transaction state outcomes', function (array $invalidState) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    expect(fn () => DB::transaction(fn () => DB::table('transaction_state_changes')->insert([
        'user_id' => $owner->id,
        'transaction_id' => $transaction->id,
        'idempotency_key' => (string) Str::uuid(),
        'operation' => TransactionVoidOperation::Void->value,
        'expected_revision' => 1,
        'result_revision' => 2,
        'result_voided_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$invalidState,
    ])))->toThrow(QueryException::class);
})->with([
    'unsupported operation' => [['operation' => 'archive']],
    'non-sequential revision' => [['result_revision' => 3]],
    'void result without void timestamp' => [['result_voided_at' => null]],
    'restore result with void timestamp' => [[
        'operation' => TransactionVoidOperation::Restore->value,
        'result_voided_at' => now(),
    ]],
]);

test('PostgreSQL exposes the active ledger, voided ledger, review, and idempotency indexes', function () {
    $indexes = collect(DB::select(
        <<<'SQL'
            SELECT tablename, indexname, indexdef
            FROM pg_indexes
            WHERE schemaname = current_schema()
                AND (
                    (tablename = 'transactions' AND indexname IN (
                        'transactions_active_ledger_index',
                        'transactions_voided_ledger_index',
                        'transactions_review_queue_index'
                    ))
                    OR (
                        tablename = 'transaction_state_changes'
                        AND indexname = 'transaction_state_changes_user_id_idempotency_key_unique'
                    )
                )
            SQL,
    ))->keyBy('indexname');

    expect($indexes)->toHaveKeys([
        'transactions_active_ledger_index',
        'transactions_voided_ledger_index',
        'transactions_review_queue_index',
        'transaction_state_changes_user_id_idempotency_key_unique',
    ])
        ->and($indexes['transactions_active_ledger_index']->indexdef)
        ->toContain('WHERE (voided_at IS NULL)')
        ->and($indexes['transactions_voided_ledger_index']->indexdef)
        ->toContain('WHERE (voided_at IS NOT NULL)')
        ->and($indexes['transactions_review_queue_index']->indexdef)
        ->toContain('AND (voided_at IS NULL)')
        ->and($indexes['transaction_state_changes_user_id_idempotency_key_unique']->indexdef)
        ->toContain('UNIQUE');
});

test('stale void and restore requests cannot overwrite a newer Transaction state', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create();

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => 'dcda91a6-3de8-41ed-9f8c-bf58f7944777',
        ])
        ->assertSessionHasNoErrors();

    $this->from(route('transactions.index'))
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => '3ad7f7af-c29a-4838-aa1b-ae71cd440180',
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('expected_revision');

    expect($transaction->refresh()->revision)->toBe(2)
        ->and($transaction->voided_at)->not->toBeNull();

    $this->followingRedirects()
        ->from(route('transactions.index'))
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => '0fe507a3-f0a1-45a2-8d09-af190d7d8944',
        ])
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash(
                'transaction_state_error',
                'This Transaction changed before its void state could be updated. Review the current ledger and try again.',
            ),
        );

    $this->delete(route('transactions.void.destroy', $transaction), [
        'expected_revision' => 2,
        'idempotency_key' => 'e444723a-0173-4f80-ad9e-4ac692aa66cd',
    ])->assertSessionHasNoErrors();

    $this->from(route('transactions.index'))
        ->delete(route('transactions.void.destroy', $transaction), [
            'expected_revision' => 2,
            'idempotency_key' => '9c158173-66f0-4c81-beac-1a2e02696c18',
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('expected_revision');

    expect($transaction->refresh()->revision)->toBe(3)
        ->and($transaction->voided_at)->toBeNull()
        ->and(TransactionStateChange::query()->count())->toBe(2);
});

test('voiding removes a provisional Transaction from active review and restoring returns it', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 1)
            ->has('transactions', 1),
        );

    $this->post(route('transactions.void.store', $transaction), [
        'expected_revision' => 1,
        'idempotency_key' => '7ee06829-f7c5-420c-a150-a4e8233fb97a',
    ])->assertSessionHasNoErrors();

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 0)
            ->has('transactions', 0),
        );

    $this->delete(route('transactions.void.destroy', $transaction), [
        'expected_revision' => 2,
        'idempotency_key' => '8f4ffba0-9908-4195-8405-81f995d5d8e0',
    ])->assertSessionHasNoErrors();

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 1)
            ->has('transactions', 1)
            ->where('transactions.0.revision', 3),
        );
});

test('an idempotency key cannot be reused for a different Transaction', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->create();
    $idempotencyKey = 'ff43ccbd-45a1-4218-9d4a-9755a305301c';

    $this->actingAs($owner)
        ->post(route('transactions.void.store', $firstTransaction), [
            'expected_revision' => 1,
            'idempotency_key' => $idempotencyKey,
        ])
        ->assertSessionHasNoErrors();

    $this->from(route('transactions.index'))
        ->post(route('transactions.void.store', $secondTransaction), [
            'expected_revision' => 1,
            'idempotency_key' => $idempotencyKey,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('idempotency_key');

    expect($firstTransaction->refresh()->voided_at)->not->toBeNull()
        ->and($secondTransaction->refresh()->voided_at)->toBeNull()
        ->and($secondTransaction->revision)->toBe(1)
        ->and(TransactionStateChange::query()->count())->toBe(1);
});

test('Transaction void routes require the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->make(['id' => $owner->id + 1]);
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create();
    $payload = [
        'expected_revision' => 1,
        'idempotency_key' => 'c202486e-60f0-4f3b-85cb-e26acb385a6f',
    ];

    $this->post(route('transactions.void.store', $transaction), $payload)
        ->assertRedirect(route('login'));

    $this->actingAs($otherOwner)
        ->post(route('transactions.void.store', $transaction), $payload)
        ->assertForbidden();

    expect($transaction->refresh()->revision)->toBe(1)
        ->and($transaction->voided_at)->toBeNull()
        ->and(TransactionStateChange::query()->count())->toBe(0);
});

test('invalid void command input leaves the Transaction unchanged', function (
    array $invalidPayload,
    string $invalidField,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->create();

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->post(route('transactions.void.store', $transaction), [
            'expected_revision' => 1,
            'idempotency_key' => 'a30cbfd7-72ee-45dc-88d1-a0f810ac1ca4',
            ...$invalidPayload,
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors($invalidField);

    expect($transaction->refresh()->revision)->toBe(1)
        ->and($transaction->voided_at)->toBeNull()
        ->and(TransactionStateChange::query()->count())->toBe(0);
})->with([
    'missing expected revision' => [
        ['expected_revision' => null],
        'expected_revision',
    ],
    'non-positive expected revision' => [
        ['expected_revision' => 0],
        'expected_revision',
    ],
    'missing idempotency key' => [
        ['idempotency_key' => null],
        'idempotency_key',
    ],
    'malformed idempotency key' => [
        ['idempotency_key' => 'not-a-uuid'],
        'idempotency_key',
    ],
]);
