<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\Actions\Ledger\ResolveTransactionField;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('a confirmed Transaction with multiple provisional fields remains in totals and appears once in the Review Queue', function () {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Neighborhood market',
        provisionalFields: [
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::MerchantDescription,
        ],
    );

    expect($transaction->confirmed_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '12345')
            ->where('transactions.0.id', $transaction->id),
        );

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('review-queue/index')
            ->where('unresolved_field_count', 2)
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id)
            ->where('transactions.0.revision', 1)
            ->where('transactions.0.confirmed_at', fn (mixed $confirmedAt) => is_string($confirmedAt))
            ->where('transactions.0.fields.0.name', 'occurred_on')
            ->where('transactions.0.fields.0.value', '2026-07-22')
            ->where('transactions.0.fields.1.name', 'merchant_description')
            ->where('transactions.0.fields.1.value', 'Neighborhood market'),
        );
});

test('an Uncategorized Transaction remains in totals, reports in its system bucket, and enters the Review Queue', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $uncategorized = Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'amount_minor' => 10_000,
        'merchant_description' => 'Needs a Category',
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'amount_minor' => 5_000,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '15000')
            ->where('category_totals.0.category.id', $category->id)
            ->where('category_totals.0.totals.USD', '5000')
            ->where('category_totals.1.category.id', null)
            ->where('category_totals.1.category.name', 'Uncategorized')
            ->where('category_totals.1.totals.USD', '10000'));

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->where('review_queue.outstanding_count', 1)
            ->has('workspace_transactions', 1)
            ->where('workspace_transactions.0.id', $uncategorized->id)
            ->where('selected_transaction.id', $uncategorized->id));
});

test('the owner can accept one provisional field and correct another with totals recalculated immediately', function () {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Neighborhood market',
        provisionalFields: [
            ReviewableTransactionField::MerchantDescription,
            ReviewableTransactionField::AmountMinor,
        ],
    );

    $this->actingAs($owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => ReviewableTransactionField::MerchantDescription,
        ]), [
            'expected_revision' => 1,
            'resolution' => 'accept',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 1)
            ->where('transactions.0.revision', 2)
            ->where('transactions.0.fields.0.name', 'amount_minor')
            ->where('transactions.0.fields.0.value', '12345'),
        );

    $this->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => ReviewableTransactionField::AmountMinor,
    ]), [
        'expected_revision' => 2,
        'resolution' => 'correct',
        'value' => '9000',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 0)
            ->has('transactions', 0),
        );

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '9000')
            ->where('transactions.0.amount_minor', '9000'),
        );
});

test('a stale expected revision is rejected with the current Transaction state for re-review', function () {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Provisional market',
        provisionalFields: [
            ReviewableTransactionField::MerchantDescription,
            ReviewableTransactionField::AmountMinor,
        ],
    );

    app(ResolveTransactionField::class)->handle(
        owner: $owner,
        transaction: $transaction,
        field: ReviewableTransactionField::MerchantDescription,
        expectedRevision: 1,
        resolution: TransactionFieldResolution::Correct,
        correctedValue: 'Neighborhood market',
    );

    try {
        app(ResolveTransactionField::class)->handle(
            owner: $owner,
            transaction: $transaction,
            field: ReviewableTransactionField::AmountMinor,
            expectedRevision: 1,
            resolution: TransactionFieldResolution::Correct,
            correctedValue: '9000',
        );

        $this->fail('A stale Correction was accepted.');
    } catch (StaleTransactionRevision $exception) {
        expect($exception->currentState())
            ->toMatchArray([
                'id' => $transaction->id,
                'revision' => 2,
                'amount_minor' => '12345',
                'merchant_description' => 'Neighborhood market',
                'provisional_fields' => ['amount_minor'],
            ]);
    }

    $this->actingAs($owner)
        ->followingRedirects()
        ->from(route('review_queue.index'))
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => ReviewableTransactionField::AmountMinor,
        ]), [
            'expected_revision' => 1,
            'resolution' => 'correct',
            'value' => '9000',
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('errors.expected_revision', 'This Transaction changed while you were reviewing it. Review the current values and try again.')
            ->where('transactions.0.revision', 2)
            ->where('transactions.0.amount_minor', '12345')
            ->where('transactions.0.fields.0.name', 'amount_minor')
            ->where('transactions.0.fields.0.value', '12345'),
        );
});

test('a stale response returns current state after the final flagged field was resolved elsewhere', function () {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Neighborhood market',
        provisionalFields: [ReviewableTransactionField::AmountMinor],
    );

    app(ResolveTransactionField::class)->handle(
        owner: $owner,
        transaction: $transaction,
        field: ReviewableTransactionField::AmountMinor,
        expectedRevision: 1,
        resolution: TransactionFieldResolution::Correct,
        correctedValue: '9000',
    );

    $this->actingAs($owner)
        ->followingRedirects()
        ->from(route('review_queue.index'))
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => ReviewableTransactionField::AmountMinor,
        ]), [
            'expected_revision' => 1,
            'resolution' => 'correct',
            'value' => '8000',
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('errors.expected_revision', 'This Transaction changed while you were reviewing it. Review the current values and try again.')
            ->has('transactions', 0)
            ->where('stale_transaction.id', $transaction->id)
            ->where('stale_transaction.revision', 2)
            ->where('stale_transaction.amount_minor', '9000')
            ->where('stale_transaction.provisional_fields', []),
        );
});

test('Corrections persist authoritative values for every reviewable Transaction field', function (
    ReviewableTransactionField $field,
    string $correctedValue,
    string $expectedValue,
    string $expectedUsdTotal,
    string $expectedPenTotal,
) {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Provisional market',
        provisionalFields: [$field],
    );

    $this->actingAs($owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => $field,
        ]), [
            'expected_revision' => 1,
            'resolution' => 'correct',
            'value' => $correctedValue,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    $transaction->refresh();
    $correction = $transaction->corrections()->sole();

    expect($field->valueFor($transaction))->toBe($expectedValue)
        ->and($transaction->revision)->toBe(2)
        ->and($transaction->provisional_fields)->toBe([])
        ->and($correction->field)->toBe($field)
        ->and($correction->corrected_value)->toBe($expectedValue)
        ->and($correction->transaction_revision)->toBe(2);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', $expectedUsdTotal)
            ->where('totals.PEN', $expectedPenTotal),
        );
})->with([
    'occurrence date' => [
        ReviewableTransactionField::OccurredOn,
        '2026-07-23',
        '2026-07-23',
        '12345',
        '0',
    ],
    'amount' => [
        ReviewableTransactionField::AmountMinor,
        '9000',
        '9000',
        '9000',
        '0',
    ],
    'currency' => [
        ReviewableTransactionField::Currency,
        'PEN',
        'PEN',
        '0',
        '12345',
    ],
    'kind' => [
        ReviewableTransactionField::Kind,
        'refund',
        'refund',
        '-12345',
        '0',
    ],
    'merchant or description' => [
        ReviewableTransactionField::MerchantDescription,
        'Neighborhood market',
        'Neighborhood market',
        '12345',
        '0',
    ],
]);

test('Review Queue routes require an authenticated owner', function () {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Provisional market',
        provisionalFields: [ReviewableTransactionField::MerchantDescription],
    );

    $this->get(route('review_queue.index'))
        ->assertRedirect(route('login'));

    $this->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => ReviewableTransactionField::MerchantDescription,
    ]), [
        'expected_revision' => 1,
        'resolution' => 'accept',
    ])->assertRedirect(route('login'));
});

test('invalid field review input leaves the confirmed Transaction unchanged', function (
    ReviewableTransactionField $field,
    array $payload,
    string $invalidField,
) {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Provisional market',
        provisionalFields: [$field],
    );

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => $field,
        ]), [
            'expected_revision' => 1,
            'resolution' => 'correct',
            'value' => 'Valid replacement',
            ...$payload,
        ])
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors($invalidField);

    expect($transaction->refresh()->revision)->toBe(1)
        ->and($transaction->provisional_fields)->toBe([$field->value])
        ->and($transaction->corrections()->count())->toBe(0);
})->with([
    'missing corrected value' => [
        ReviewableTransactionField::MerchantDescription,
        ['value' => null],
        'value',
    ],
    'invalid occurrence date' => [
        ReviewableTransactionField::OccurredOn,
        ['value' => '2026-02-30'],
        'value',
    ],
    'fractional amount' => [
        ReviewableTransactionField::AmountMinor,
        ['value' => '1.5'],
        'value',
    ],
    'unsupported currency' => [
        ReviewableTransactionField::Currency,
        ['value' => 'EUR'],
        'value',
    ],
    'unsupported kind' => [
        ReviewableTransactionField::Kind,
        ['value' => 'transfer'],
        'value',
    ],
    'merchant above maximum length' => [
        ReviewableTransactionField::MerchantDescription,
        ['value' => str_repeat('a', 256)],
        'value',
    ],
    'non-positive expected revision' => [
        ReviewableTransactionField::MerchantDescription,
        ['expected_revision' => 0],
        'expected_revision',
    ],
    'unsupported resolution' => [
        ReviewableTransactionField::MerchantDescription,
        ['resolution' => 'revise'],
        'resolution',
    ],
]);

test('the Review Queue exposes every unresolved Transaction field', function () {
    $owner = User::factory()->create();

    Transaction::factory()
        ->count(101)
        ->for($owner, 'owner')
        ->provisional([
            ReviewableTransactionField::MerchantDescription,
        ])
        ->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 101)
            ->has('transactions', 101),
        );
});

test('PostgreSQL rejects non-reviewable provisional Transaction fields', function () {
    $transaction = Transaction::factory()->create();

    expect(fn () => DB::table($transaction->getTable())
        ->where('id', $transaction->id)
        ->update(['provisional_fields' => json_encode(['unreviewable'])]))
        ->toThrow(QueryException::class);
});
