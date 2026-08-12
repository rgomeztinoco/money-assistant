<?php

use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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

    $this->post(route('merchant_rules.store'), [
        'merchant' => '  CAFÉ—Central!!!  ',
        'category_id' => $category->id,
        'transaction_kind' => 'purchase',
        'currency' => 'PEN',
        'enabled' => true,
    ])->assertRedirect(route('merchant_rules.index'))
        ->assertSessionHasNoErrors();

    $rule = MerchantRule::query()->sole();

    expect($rule)
        ->user_id->toBe($owner->id)
        ->category_id->toBe($category->id)
        ->merchant->toBe('CAFÉ—Central!!!')
        ->merchant_key->toBe('café central')
        ->enabled->toBeTrue();
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

    $this->patch(route('merchant_rules.update', $rule), [
        'merchant' => 'New Merchant',
        'category_id' => $secondCategory->id,
        'transaction_kind' => 'refund',
        'currency' => 'USD',
        'enabled' => false,
    ])->assertRedirect(route('merchant_rules.index'))
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

    $this->delete(route('merchant_rules.destroy', $rule))
        ->assertRedirect(route('merchant_rules.index'));

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
        'transaction_kind' => 'purchase',
        'currency' => 'PEN',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    $this->post(route('merchant_rules.store'), [
        'merchant' => "CAFE\u{0301}---CENTRAL",
        'category_id' => $category->id,
        'transaction_kind' => 'purchase',
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
        'transaction_kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $this->actingAs($owner)
        ->patch(route('merchant_rules.update', $scopedRule), [
            'merchant' => 'Overlap Merchant',
            'category_id' => $category->id,
            'transaction_kind' => 'purchase',
            'currency' => 'PEN',
            'enabled' => true,
        ])->assertSessionHasErrors('enabled');

    expect($scopedRule->fresh()->enabled)->toBeFalse();
});

test('the revisioned Learned Rule product is absent', function () {
    foreach ([
        'learned_rules',
        'learned_rule_revisions',
        'learned_rule_suggestions',
        'learned_rule_suggestion_evidence',
        'learned_rule_change_previews',
        'learned_rule_bulk_actions',
        'learned_rule_bulk_action_items',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Route::has('learned_rules.index'))->toBeFalse()
        ->and(Route::has('learned_rule_previews.store'))->toBeFalse()
        ->and(Route::has('learned_rules.historical_applications.store'))->toBeFalse()
        ->and(Route::has('learned_rule_bulk_actions.confirmation.store'))->toBeFalse()
        ->and(file_exists(app_path('Models/LearnedRule.php')))->toBeFalse();
});
