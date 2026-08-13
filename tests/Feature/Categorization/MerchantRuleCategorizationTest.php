<?php

use App\CategoryAssignmentProvenance;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('an exact Merchant Rule categorizes only matching future Transactions after Unicode punctuation case and whitespace normalization', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    $historicalTransaction = Transaction::factory()->create([
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
    $merchantRule = MerchantRule::query()->sole();

    expect($futureTransaction)
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule)
        ->merchant_rule_id->toBe($merchantRule->id);

    $this->get(route('transactions.index', ['selected' => $futureTransaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.category.provenance.source', 'merchant_rule')
                ->where('selected_transaction.category.provenance.merchant_rule.id', $merchantRule->id)));
});

test('disabled and out-of-scope Merchant Rules leave new Transactions Uncategorized', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    $merchant = 'Scoped Merchant';
    $rule = MerchantRule::factory()->for($category)->disabled()->create([
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

test('deleting a Merchant Rule preserves its truthful current Category provenance', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    $rule = MerchantRule::factory()->for($category)->create();
    $transaction = Transaction::factory()->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::MerchantRule,
        'merchant_rule_id' => $rule->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('merchant_rules.destroy', $rule))
        ->assertSessionHasNoErrors();

    $this->assertSoftDeleted($rule);

    expect($transaction->refresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule)
        ->merchant_rule_id->toBe($rule->id);

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.category.provenance.source', 'merchant_rule')
                ->where('selected_transaction.category.provenance.merchant_rule.id', $rule->id)));
});
