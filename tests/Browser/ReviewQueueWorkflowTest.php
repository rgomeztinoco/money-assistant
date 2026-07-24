<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\Actions\Ledger\ResolveTransactionField;
use App\Currency;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use App\TransactionKind;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner accepts, corrects, and re-reviews stale uncertain Transaction fields', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Purchase,
        merchantDescription: 'Provisional market',
        provisionalFields: [
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::AmountMinor,
            ReviewableTransactionField::MerchantDescription,
        ],
    );

    $page = visit('/review-queue');

    $page
        ->assertSee('3 fields')
        ->assertSee('Included in spending totals')
        ->press('Accept current')
        ->assertSee('2 fields')
        ->assertSee('Amount in minor units')
        ->assertSee('Merchant or description');

    app(ResolveTransactionField::class)->handle(
        owner: $owner,
        transaction: $transaction,
        field: ReviewableTransactionField::MerchantDescription,
        expectedRevision: 2,
        resolution: TransactionFieldResolution::Correct,
        correctedValue: 'Neighborhood market',
    );

    $page
        ->fill('Correct Amount in minor units', '9000')
        ->press('Save Correction')
        ->assertSee(
            'This Transaction changed while you were reviewing it. Review the current values and try again.',
        )
        ->assertSee('Neighborhood market')
        ->assertSee('1 field')
        ->fill('Correct Amount in minor units', '9000')
        ->press('Save Correction')
        ->assertSee('Review Queue is clear')
        ->click('Transactions')
        ->assertSee('$ 90.00')
        ->assertSee('Neighborhood market')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
