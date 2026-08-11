<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionKind;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner edits all current values and clears the Review Queue directly', function () {
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
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction->update([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $page = visit('/review-queue');

    $page
        ->assertSee('3 reviews')
        ->assertSee('Included in spending totals')
        ->fill('Edit occurrence date', '2026-07-23')
        ->fill('Edit amount in minor units', '9000')
        ->fill('Edit merchant or description', 'Neighborhood market')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertSee('Review Queue is clear')
        ->click('Transactions')
        ->assertSee('$ 90.00')
        ->assertSee('Neighborhood market')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
