<?php

use App\CategoryAssignmentProvenance;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;

test('an exact Merchant Rule categorizes only matching future Transactions after Unicode punctuation case and whitespace normalization', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $historicalTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Café Central',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);
    $this->actingAs($owner)
        ->post(route('merchant_rules.store'), [
            'merchant' => 'CAFÉ—CENTRAL',
            'category_id' => $category->id,
            'transaction_kind' => 'purchase',
            'currency' => 'PEN',
            'enabled' => true,
        ])->assertSessionHasNoErrors();

    expect($historicalTransaction->fresh()->category_id)->toBeNull();

    $this->post(route('transactions.store'), [
        'occurred_on' => '2026-08-10',
        'amount_minor' => 1_000,
        'currency' => 'PEN',
        'kind' => 'purchase',
        'merchant_description' => "  cafe\u{0301}...central  ",
    ])->assertSessionHasNoErrors();

    $futureTransaction = Transaction::query()->latest('id')->firstOrFail();

    expect($futureTransaction)
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule);
});

test('disabled and out-of-scope Merchant Rules leave new Transactions Uncategorized', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $merchant = 'Scoped Merchant';
    $rule = MerchantRule::factory()->for($owner, 'owner')->for($category)->disabled()->create([
        'merchant' => $merchant,
        'merchant_key' => app(MerchantNormalizer::class)->normalize($merchant),
        'transaction_kind' => 'refund',
        'currency' => 'USD',
    ]);
    $this->actingAs($owner);

    $record = function (string $kind, string $currency): Transaction {
        $this->post(route('transactions.store'), [
            'occurred_on' => '2026-08-10',
            'amount_minor' => 1_000,
            'currency' => $currency,
            'kind' => $kind,
            'merchant_description' => 'Scoped Merchant',
        ])->assertSessionHasNoErrors();

        return Transaction::query()->latest('id')->firstOrFail();
    };

    expect($record('refund', 'USD')->category_id)->toBeNull();

    $this->patch(route('merchant_rules.update', $rule), [
        'merchant' => $merchant,
        'category_id' => $category->id,
        'transaction_kind' => 'refund',
        'currency' => 'USD',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    expect($record('purchase', 'USD')->category_id)->toBeNull()
        ->and($record('refund', 'PEN')->category_id)->toBeNull()
        ->and($record('refund', 'USD')->category_id)->toBe($category->id);
});
