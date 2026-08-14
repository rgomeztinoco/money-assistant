<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('a confirmed Transaction with provisional fields remains in totals and appears once in the Review Queue', function () {
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
            ->missing('totals')
            ->where('transactions.0.id', $transaction->id));

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('review-queue/index')
            ->where('unresolved_field_count', 2)
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id)
            ->where('transactions.0.confirmed_at', fn (mixed $confirmedAt) => is_string($confirmedAt))
            ->where('transactions.0.fields.0.name', 'occurred_on')
            ->where('transactions.0.fields.0.value', '2026-07-22')
            ->where('transactions.0.fields.1.name', 'merchant_description')
            ->where('transactions.0.fields.1.value', 'Neighborhood market'));
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
            ->missing('totals')
            ->missing('category_totals'));

    $report = $this->get(route('reports.show', [
        'currency' => Currency::Usd,
        'date_from' => '2000-01-01',
        'date_to' => now()->toDateString(),
    ]));
    $categoryTotals = collect($report->inertiaProps('category_groups'))
        ->flatMap(fn (array $group): array => [$group, ...$group['children']]);

    expect($categoryTotals->firstWhere('category.id', $category->id)['amount_minor'])
        ->toBe('5000')
        ->and($categoryTotals->firstWhere('category.id', null)['amount_minor'])
        ->toBe('10000');

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->has('transactions', 1)
            ->where('transactions.0.id', $uncategorized->id)
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.id', $uncategorized->id)));
});

test('the owner can accept one provisional field and replace another value', function () {
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
        ]), ['resolution' => 'accept'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 1)
            ->where('transactions.0.fields.0.name', 'amount_minor'));

    $this->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => ReviewableTransactionField::AmountMinor,
    ]), [
        'resolution' => 'correct',
        'value' => '9000',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    expect($transaction->refresh())
        ->amount_minor->toBe(9000)
        ->provisional_fields->toBe([]);
});

test('direct edits replace the current value for every reviewable Transaction field', function (
    ReviewableTransactionField $field,
    string $replacementValue,
    string $expectedValue,
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
            'resolution' => 'correct',
            'value' => $replacementValue,
        ])
        ->assertSessionHasNoErrors();

    $transaction->refresh();

    expect($field->valueFor($transaction))->toBe($expectedValue)
        ->and($transaction->provisional_fields)->toBe([]);
})->with([
    'occurrence date' => [ReviewableTransactionField::OccurredOn, '2026-07-23', '2026-07-23'],
    'amount' => [ReviewableTransactionField::AmountMinor, '9000', '9000'],
    'currency' => [ReviewableTransactionField::Currency, 'PEN', 'PEN'],
    'kind' => [ReviewableTransactionField::Kind, 'refund', 'refund'],
    'merchant or description' => [ReviewableTransactionField::MerchantDescription, 'Neighborhood market', 'Neighborhood market'],
]);

test('Review Queue routes require an authenticated owner', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create();

    $this->get(route('review_queue.index'))->assertRedirect(route('login'));
    $this->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => ReviewableTransactionField::MerchantDescription,
    ]), ['resolution' => 'accept'])->assertRedirect(route('login'));
});

test('invalid field review input leaves the confirmed Transaction unchanged', function (
    ReviewableTransactionField $field,
    array $payload,
    string $invalidField,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([$field])
        ->create();

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => $field,
        ]), [
            'resolution' => 'correct',
            'value' => 'Valid replacement',
            ...$payload,
        ])
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors($invalidField);

    expect($transaction->refresh()->provisional_fields)->toBe([$field->value]);
})->with([
    'missing corrected value' => [ReviewableTransactionField::MerchantDescription, ['value' => null], 'value'],
    'invalid occurrence date' => [ReviewableTransactionField::OccurredOn, ['value' => '2026-02-30'], 'value'],
    'fractional amount' => [ReviewableTransactionField::AmountMinor, ['value' => '1.5'], 'value'],
    'unsupported currency' => [ReviewableTransactionField::Currency, ['value' => 'EUR'], 'value'],
    'unsupported kind' => [ReviewableTransactionField::Kind, ['value' => 'transfer'], 'value'],
    'merchant above maximum length' => [ReviewableTransactionField::MerchantDescription, ['value' => str_repeat('a', 256)], 'value'],
    'unsupported resolution' => [ReviewableTransactionField::MerchantDescription, ['resolution' => 'revise'], 'resolution'],
]);

test('the Review Queue exposes every unresolved Transaction field', function () {
    $owner = User::factory()->create();
    Transaction::factory()
        ->count(101)
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 101)
            ->has('transactions', 25)
            ->where('pagination.total', 101));
});

test('the Review Queue rejects non-reviewable Transaction fields', function () {
    $transaction = Transaction::factory()->create();

    $this->actingAs($transaction->owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => 'unreviewable',
        ]), [
            'resolution' => 'accept',
        ])
        ->assertNotFound();
});
