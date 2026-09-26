<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can view and create an exact Merchant Rule', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);

    $this->actingAs($owner)
        ->get(route('merchant_rules.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('merchant-rules/index')
            ->has('rules', 0)
            ->where('category_options.0.id', $category->id)
            ->where('category_options.0.path', 'Groceries'));

    $context = route('merchant_rules.index', [
        'status' => 'enabled',
        'sort' => 'merchant',
        'direction' => 'desc',
    ]);

    $this->from($context)
        ->post(route('merchant_rules.store'), [
            'merchant' => '  CAFÉ—Central!!!  ',
            'category_id' => $category->id,
            'transaction_kind' => 'spending',
            'currency' => 'PEN',
            'enabled' => true,
        ])->assertRedirect($context)
        ->assertSessionHasNoErrors();

    $rule = MerchantRule::query()->sole();

    expect($rule)
        ->user_id->toBe($owner->id)
        ->category_id->toBe($category->id)
        ->merchant->toBe('CAFÉ—Central!!!')
        ->merchant_key->toBe('café central')
        ->enabled->toBeTrue();
});

test('a rule preview shows exact existing matches and excludes split or voided Transactions', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $matching = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['description' => 'Café Central', 'amount_minor' => 1250]);
    $split = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['description' => 'Café Central']);
    ReceiptBreakdown::factory()->for($split)->create();
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['description' => 'Café Central', 'voided_at' => now()]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create(['description' => 'Café Central']);
    Transaction::factory()->for($otherOwner, 'owner')->spending()->pen()->create(['description' => 'Café Central']);

    $this->actingAs($owner)
        ->getJson(route('merchant_rules.matches', [
            'merchant' => 'CAFE CENTRAL',
            'transaction_kind' => 'spending',
            'currency' => 'PEN',
        ]))
        ->assertOk()
        ->assertJsonPath('count', 0);

    $this->getJson(route('merchant_rules.matches', [
        'merchant' => 'CAFÉ—CENTRAL',
        'transaction_kind' => 'spending',
        'currency' => 'PEN',
    ]))->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('transactions.0.id', $matching->id)
        ->assertJsonPath('transactions.0.amount_minor', '1250');
});

test('rule preview rejects a merchant without a searchable key', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->getJson(route('merchant_rules.matches', ['merchant' => '!!!']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('merchant');
});

test('a future-only rule leaves existing Transactions unchanged', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['description' => 'Market Plaza']);

    $this->actingAs($owner)
        ->post(route('merchant_rules.store'), [
            'merchant' => 'Market Plaza',
            'category_id' => $category->id,
            'transaction_kind' => 'spending',
            'currency' => 'PEN',
            'enabled' => true,
            'apply_existing' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->refresh()->category_id)->toBeNull();
});

test('creating a rule can apply to existing matching Transactions while preserving the source workspace', function () {
    $owner = User::factory()->create();
    $oldCategory = Category::factory()->for($owner, 'owner')->create();
    $newCategory = Category::factory()->for($owner, 'owner')->create();
    $matching = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'description' => 'Market Plaza',
        'category_id' => $oldCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $otherKind = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create(['description' => 'Market Plaza']);
    $workspace = route('breakdown.index', ['period' => 'month']);

    $this->actingAs($owner)
        ->withHeader('X-Inertia', 'true')
        ->from($workspace)
        ->post(route('merchant_rules.store'), [
            'merchant' => 'Market Plaza',
            'category_id' => $newCategory->id,
            'transaction_kind' => 'spending',
            'currency' => 'PEN',
            'enabled' => true,
            'apply_existing' => true,
            'source_transaction_id' => $matching->id,
        ])
        ->assertRedirect($workspace)
        ->assertSessionHasNoErrors();

    expect($matching->refresh()->category_id)->toBe($newCategory->id)
        ->and($matching->category_assignment_provenance)->toBe(CategoryAssignmentProvenance::MerchantRule)
        ->and($otherKind->refresh()->category_id)->toBeNull();
});

test('the Merchant Rules payload groups rules by full Category path', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $foodOther = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Other']);
    $travelOther = Category::factory()->for($owner, 'owner')->for($travel, 'parent')->create(['name' => 'Other']);
    $unused = Category::factory()->for($owner, 'owner')->create(['name' => 'Unused']);
    MerchantRule::factory()->count(2)->for($owner, 'owner')->for($foodOther)->create();
    MerchantRule::factory()->for($owner, 'owner')->for($travelOther)->create();

    $this->actingAs($owner)
        ->get(route('merchant_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rules', 3)
            ->where('rules.0.category_path', 'Food > Other')
            ->where('rules.2.category_path', 'Travel > Other')
            ->has('category_groups', 2)
            ->where('category_groups.0.id', $foodOther->id)
            ->where('category_groups.0.path', 'Food > Other')
            ->where('category_groups.0.rule_count', 2)
            ->where('category_groups.1.id', $travelOther->id)
            ->where('category_groups.1.path', 'Travel > Other')
            ->where('category_groups.1.rule_count', 1)
            ->where('category_options', fn ($options) => collect($options)->contains('id', $unused->id)));
});

test('Merchant Rule search is global and filters and sorting use validated inputs', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $cafes = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Cafés']);
    $taxis = Category::factory()->for($owner, 'owner')->for($travel, 'parent')->create(['name' => 'Taxis']);
    MerchantRule::factory()->for($owner, 'owner')->for($cafes)->create([
        'merchant' => 'Central Coffee',
        'merchant_key' => 'central coffee',
        'transaction_kind' => 'spending',
        'currency' => 'PEN',
        'enabled' => true,
    ]);
    MerchantRule::factory()->for($owner, 'owner')->for($taxis)->disabled()->create([
        'merchant' => 'Airport Taxi',
        'merchant_key' => 'airport taxi',
        'transaction_kind' => 'refund',
        'currency' => 'USD',
    ]);

    $this->actingAs($owner)
        ->get(route('merchant_rules.index', ['category_id' => $cafes->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category_id', $cafes->id)
            ->has('rules', 1)
            ->where('rules.0.merchant', 'Central Coffee'));

    $this->get(route('merchant_rules.index', [
        'category_id' => $cafes->id,
        'search' => 'TAXI',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('filters.search', 'TAXI')
        ->has('rules', 1)
        ->where('rules.0.merchant', 'Airport Taxi'));

    $this->get(route('merchant_rules.index', [
        'search' => 'cafes',
        'status' => 'enabled',
        'kind' => 'spending',
        'currency' => 'PEN',
        'sort' => 'merchant',
        'direction' => 'desc',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('filters.status', 'enabled')
        ->where('filters.kind', 'spending')
        ->where('filters.currency', 'PEN')
        ->where('filters.sort', 'merchant')
        ->where('filters.direction', 'desc')
        ->has('rules', 1)
        ->where('rules.0.merchant', 'Central Coffee'));
});

test('Merchant Rule search covers kind currency and status', function (string $search) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    MerchantRule::factory()->for($owner, 'owner')->for($category)->disabled()->create([
        'merchant' => 'Airport Taxi',
        'merchant_key' => 'airport taxi',
        'transaction_kind' => 'refund',
        'currency' => 'USD',
    ]);

    $this->actingAs($owner)
        ->get(route('merchant_rules.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rules', 1)
            ->where('rules.0.merchant', 'Airport Taxi'));
})->with([
    'Transaction kind' => 'refund',
    'currency' => 'USD',
    'status' => 'disabled',
]);

test('Merchant Rule management rejects unsupported filter values', function (array $query, string $field) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('merchant_rules.index', $query))
        ->assertSessionHasErrors($field);
})->with([
    'search longer than 255 characters' => [['search' => str_repeat('a', 256)], 'search'],
    'unknown status' => [['status' => 'pending'], 'status'],
    'unknown Transaction kind' => [['kind' => 'income'], 'kind'],
    'unknown currency' => [['currency' => 'EUR'], 'currency'],
    'unknown sort column' => [['sort' => 'merchant_key'], 'sort'],
    'unknown sort direction' => [['direction' => 'sideways'], 'direction'],
]);

test("Merchant Rule management rejects another owner's Category filter", function () {
    $owner = User::factory()->create();
    $otherCategory = Category::factory()->for(User::factory(), 'owner')->create();

    $this->actingAs($owner)
        ->get(route('merchant_rules.index', ['category_id' => $otherCategory->id]))
        ->assertSessionHasErrors('category_id');
});

test("Merchant Rule creation rejects another owner's Transaction prefill", function () {
    $owner = User::factory()->create();
    $otherTransaction = Transaction::factory()->for(User::factory(), 'owner')->create();

    $this->actingAs($owner)
        ->get(route('merchant_rules.index', ['transaction' => $otherTransaction->id]))
        ->assertSessionHasErrors('transaction');
});

test('creating a Merchant Rule from a Transaction receives trusted prefill values', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'description' => '  CAFÉ—Central!!!  ',
        'kind' => 'refund',
        'currency' => 'USD',
    ]);

    $this->actingAs($owner)
        ->get(route('merchant_rules.index', ['transaction' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.transaction_id', $transaction->id)
            ->where('prefill.merchant', 'CAFÉ—Central!!!')
            ->where('prefill.merchant_key', 'café central')
            ->where('prefill.transaction_kind', 'refund')
            ->where('prefill.currency', 'USD'));
});

test('the owner can edit disable enable and delete a Merchant Rule', function () {
    $owner = User::factory()->create();
    $firstCategory = Category::factory()->for($owner, 'owner')->create();
    $secondCategory = Category::factory()->for($owner, 'owner')->create();
    $rule = MerchantRule::factory()->for($owner, 'owner')->for($firstCategory)->create([
        'merchant' => 'Old Merchant',
        'merchant_key' => 'old merchant',
    ]);
    $this->actingAs($owner);

    $context = route('merchant_rules.index', [
        'category_id' => $firstCategory->id,
        'status' => 'enabled',
        'sort' => 'merchant',
    ]);

    $this->from($context)
        ->patch(route('merchant_rules.update', $rule), [
            'merchant' => 'New Merchant',
            'category_id' => $secondCategory->id,
            'transaction_kind' => 'refund',
            'currency' => 'USD',
            'enabled' => false,
        ])->assertRedirect($context)
        ->assertSessionHasNoErrors();

    expect($rule->fresh())
        ->merchant->toBe('New Merchant')
        ->merchant_key->toBe('new merchant')
        ->category_id->toBe($secondCategory->id)
        ->transaction_kind->value->toBe('refund')
        ->currency->value->toBe('USD')
        ->enabled->toBeFalse();

    $this->patch(route('merchant_rules.update', $rule), [
        'merchant' => 'New Merchant',
        'category_id' => $secondCategory->id,
        'transaction_kind' => 'refund',
        'currency' => 'USD',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    expect($rule->fresh()->enabled)->toBeTrue();

    $this->from($context)
        ->delete(route('merchant_rules.destroy', $rule))
        ->assertRedirect($context);

    $this->assertSoftDeleted($rule);
});

test('a Merchant Rule requires an active Category owned by the owner', function () {
    $owner = User::factory()->create();
    $retiredCategory = Category::factory()->for($owner, 'owner')->create([
        'archived_at' => now(),
    ]);
    $this->actingAs($owner);

    foreach ([$retiredCategory->id, PHP_INT_MAX] as $categoryId) {
        $this->post(route('merchant_rules.store'), [
            'merchant' => 'Scoped Merchant',
            'category_id' => $categoryId,
            'transaction_kind' => null,
            'currency' => null,
            'enabled' => true,
        ])->assertSessionHasErrors('category_id');
    }

    expect(MerchantRule::query()->count())->toBe(0);
});

test('the complete normalized merchant kind and currency scope is unique', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    $this->post(route('merchant_rules.store'), [
        'merchant' => 'Café Central',
        'category_id' => $category->id,
        'transaction_kind' => 'spending',
        'currency' => 'PEN',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    $this->post(route('merchant_rules.store'), [
        'merchant' => "CAFE\u{0301}---CENTRAL",
        'category_id' => $category->id,
        'transaction_kind' => 'spending',
        'currency' => 'PEN',
        'enabled' => false,
    ])->assertSessionHasErrors('merchant');

    $this->post(route('merchant_rules.store'), [
        'merchant' => 'Café Central',
        'category_id' => $category->id,
        'transaction_kind' => 'refund',
        'currency' => 'USD',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    expect(MerchantRule::query()->count())->toBe(2);
});

test('overlapping scopes cannot be enabled at the same time', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    MerchantRule::factory()->for($owner, 'owner')->for($category)->create([
        'merchant' => 'Overlap Merchant',
        'merchant_key' => 'overlap merchant',
        'transaction_kind' => null,
        'currency' => 'PEN',
    ]);
    $scopedRule = MerchantRule::factory()->for($owner, 'owner')->for($category)->disabled()->create([
        'merchant' => 'Overlap Merchant',
        'merchant_key' => 'overlap merchant',
        'transaction_kind' => 'spending',
        'currency' => 'PEN',
    ]);

    $this->actingAs($owner)
        ->patch(route('merchant_rules.update', $scopedRule), [
            'merchant' => 'Overlap Merchant',
            'category_id' => $category->id,
            'transaction_kind' => 'spending',
            'currency' => 'PEN',
            'enabled' => true,
        ])->assertSessionHasErrors('enabled');

    expect($scopedRule->fresh()->enabled)->toBeFalse();
});
