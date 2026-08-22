<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can record a confirmed manual purchase in the ledger', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'occurred_on' => '2026-07-24',
            'amount_minor' => 12345,
            'currency' => 'USD',
            'kind' => 'purchase',
            'merchant_description' => 'Neighborhood market',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $this->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->missing('totals')
            ->has('transactions', 1)
            ->where('transactions.0.occurred_on', '2026-07-24')
            ->where('transactions.0.amount_minor', '12345')
            ->where('transactions.0.currency', 'USD')
            ->where('transactions.0.kind', 'purchase')
            ->where('transactions.0.merchant_description', 'Neighborhood market')
            ->where('transactions.0.confirmed_at', fn (mixed $confirmedAt) => is_string($confirmedAt)),
        );
});

test('the owner records USD and PEN amounts in currency units without floating point loss', function (string $currency) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'occurred_on' => '2026-07-24',
            'amount' => '12.50',
            'currency' => $currency,
            'kind' => 'purchase',
            'merchant_description' => "{$currency} purchase",
        ])
        ->assertSessionHasNoErrors();

    expect(Transaction::query()->sole()->amount_minor)->toBe(1_250);
})->with(['USD', 'PEN']);

test('manual currency-unit amounts reject ambiguous or inexact values', function (string $amount) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'occurred_on' => '2026-07-24',
            'amount' => $amount,
            'currency' => 'USD',
            'kind' => 'purchase',
            'merchant_description' => 'Invalid amount',
        ])
        ->assertSessionHasErrors('amount');

    expect(Transaction::query()->doesntExist())->toBeTrue();
})->with(['12.345', '0', '-1.00', '1e2', '12,50']);

test('manual Transaction input cannot mix currency and minor units', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'occurred_on' => '2026-07-24',
            'amount' => '12.50',
            'amount_minor' => 1_250,
            'currency' => 'USD',
            'kind' => 'purchase',
            'merchant_description' => 'Ambiguous amount',
        ])
        ->assertSessionHasErrors(['amount', 'amount_minor']);

    expect(Transaction::query()->doesntExist())->toBeTrue();
});

test('purchases and Refunds keep positive amounts and retain their kind in the ledger', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    foreach ([
        ['amount_minor' => 12345, 'currency' => 'USD', 'kind' => 'purchase', 'merchant_description' => 'USD purchase'],
        ['amount_minor' => 2345, 'currency' => 'USD', 'kind' => 'refund', 'merchant_description' => 'USD Refund'],
        ['amount_minor' => 9876, 'currency' => 'PEN', 'kind' => 'purchase', 'merchant_description' => 'PEN purchase'],
        ['amount_minor' => 876, 'currency' => 'PEN', 'kind' => 'refund', 'merchant_description' => 'PEN Refund'],
    ] as $transaction) {
        $this->post(route('transactions.store'), [
            'occurred_on' => '2026-07-24',
            ...$transaction,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->has('transactions', 4)
            ->where('transactions.0.amount_minor', '876')
            ->where('transactions.0.kind', 'refund')
            ->where('transactions.2.amount_minor', '2345')
            ->where('transactions.2.kind', 'refund'),
        );
});

test('ledger amounts remain exact beyond floating point safe integers', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    foreach ([
        ['amount_minor' => '9007199254740992', 'kind' => 'purchase', 'merchant_description' => 'Large purchase'],
        ['amount_minor' => '1', 'kind' => 'refund', 'merchant_description' => 'Small Refund'],
    ] as $transaction) {
        $this->post(route('transactions.store'), [
            'occurred_on' => '2026-07-24',
            'currency' => 'USD',
            ...$transaction,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->has('transactions', 2)
            ->where('transactions.0.amount_minor', '1')
            ->where('transactions.1.amount_minor', '9007199254740992'),
        );
});

test('manual Transaction routes require an authenticated owner', function () {
    $this->get(route('transactions.index'))
        ->assertRedirect(route('login'));

    $this->post(route('transactions.store'), [
        'occurred_on' => '2026-07-24',
        'amount_minor' => 12345,
        'currency' => 'USD',
        'kind' => 'purchase',
        'merchant_description' => 'Neighborhood market',
    ])->assertRedirect(route('login'));
});

test('the shared Ledger Action rejects invalid minor units', function (mixed $amountMinor) {
    $owner = User::factory()->create();

    expect(fn () => app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-24'),
        amountMinor: $amountMinor,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Neighborhood market',
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'floating point' => 1.5,
    'zero' => 0,
    'negative integer' => -1,
]);

test('the shared Ledger Action rejects a blank merchant or description', function () {
    $owner = User::factory()->create();

    expect(fn () => app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-24'),
        amountMinor: 1,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: '   ',
    ))->toThrow(InvalidArgumentException::class);
});

test('the shared Ledger Action normalizes the merchant or description', function () {
    $owner = User::factory()->create();

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-24'),
        amountMinor: 1,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: '  Neighborhood   market  ',
    );

    expect($transaction->merchant_description)->toBe('Neighborhood market');
});

test('the ledger read path returns its first 25 Transactions with pagination metadata', function () {
    $owner = User::factory()->create();

    Transaction::factory()
        ->count(101)
        ->for($owner, 'owner')
        ->create();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions', 25)
            ->where('pagination.total', 101)
            ->where('pagination.last_page', 5),
        );
});

test('the Transaction workflow rejects invalid values before persistence', function (
    string $invalidField,
    int|string $invalidValue,
) {
    $owner = User::factory()->create();

    $payload = [
        'occurred_on' => '2026-07-24',
        'amount_minor' => 1,
        'currency' => 'USD',
        'kind' => 'purchase',
        'merchant_description' => 'Neighborhood market',
    ];
    $payload[$invalidField] = $invalidValue;

    $this->actingAs($owner)
        ->post(route('transactions.store'), $payload)
        ->assertSessionHasErrors($invalidField);

    expect(Transaction::query()->doesntExist())->toBeTrue();
})->with([
    'non-positive amount' => ['amount_minor', 0],
    'unsupported currency' => ['currency', 'EUR'],
    'unsupported kind' => ['kind', 'transfer'],
]);

test('invalid manual Transaction input is rejected without affecting the ledger', function (
    string $invalidField,
    mixed $invalidValue,
) {
    $owner = User::factory()->create();

    $payload = [
        'occurred_on' => '2026-07-24',
        'amount_minor' => 12345,
        'currency' => 'USD',
        'kind' => 'purchase',
        'merchant_description' => 'Neighborhood market',
    ];
    $payload[$invalidField] = $invalidValue;

    $this->actingAs($owner)
        ->from(route('transactions.index'))
        ->post(route('transactions.store'), $payload)
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors($invalidField);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->has('transactions', 0),
        );
})->with([
    'missing occurrence date' => ['occurred_on', null],
    'invalid occurrence date' => ['occurred_on', '2026-02-30'],
    'zero amount' => ['amount_minor', 0],
    'negative amount' => ['amount_minor', -1],
    'integral floating point amount' => ['amount_minor', 1.0],
    'fractional amount' => ['amount_minor', '1.5'],
    'amount above PostgreSQL bigint range' => ['amount_minor', '9223372036854775808'],
    'unsupported currency' => ['currency', 'EUR'],
    'unsupported kind' => ['kind', 'transfer'],
    'missing merchant or description' => ['merchant_description', ''],
    'merchant or description above maximum length' => ['merchant_description', str_repeat('a', 256)],
]);
