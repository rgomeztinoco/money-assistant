<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can read create rename and move the two-level taxonomy directly', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    $this->actingAs($owner)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('categories/index')
            ->has('categories', 2)
            ->where('categories.0.name', 'Food')
            ->where('categories.0.children', [])
            ->where('categories.0.archived_at', null)
            ->missing('categories.0.revision')
            ->missing('trashed_categories'));

    $this->post(route('categories.store'), [
        'name' => '  Local   Transport ',
        'parent_id' => $transport->id,
    ])->assertRedirect(route('categories.index'));

    $category = Category::query()->where('name', 'Local Transport')->sole();

    expect($category->parent_id)->toBe($transport->id);

    $this->patch(route('categories.update', $category), [
        'name' => 'Local Transit',
        'parent_id' => $food->id,
    ])->assertRedirect(route('categories.index'));

    expect($category->fresh())
        ->id->toBe($category->id)
        ->name->toBe('Local Transit')
        ->parent_id->toBe($food->id);
});

test('active Category names are case-insensitively unique among siblings and the hierarchy stops at two levels', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $pets = Category::factory()->for($owner, 'owner')->create(['name' => 'Pets']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);

    $this->actingAs($owner)
        ->post(route('categories.store'), [
            'name' => ' dining ',
            'parent_id' => $food->id,
        ])
        ->assertSessionHasErrors('name');

    $this->post(route('categories.store'), [
        'name' => 'Dining',
        'parent_id' => $pets->id,
    ])->assertSessionHasNoErrors();

    $this->post(route('categories.store'), [
        'name' => 'Third level',
        'parent_id' => $dining->id,
    ])->assertSessionHasErrors('parent_id');

    expect(Category::query()->where('name', 'Dining')->count())->toBe(2);
});

test('renaming and moving a Category preserves its identity on historical Transactions and reports', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $category = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Cafes',
    ]);
    $transaction = Transaction::factory()->for($owner, 'owner')->pen()->create([
        'occurred_on' => today(),
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)->patch(route('categories.update', $category), [
        'name' => 'Coffee Shops',
        'parent_id' => $travel->id,
    ])->assertSessionHasNoErrors();

    expect($transaction->fresh()->category_id)->toBe($category->id);

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.category.id', $category->id)
                ->where('selected_transaction.category.name', 'Coffee Shops')));

    $this->get(route('reports.show', [
        'currency' => 'PEN',
        'date_from' => today()->startOfMonth()->toDateString(),
    ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_groups.0.category.name', 'Travel')
            ->where('category_groups.0.children.0.category.name', 'Coffee Shops'));
});

test('archiving a Category preserves current assignments and reporting while preventing future assignments', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create([
        'name' => 'Dining',
    ]);
    $merchantRule = MerchantRule::factory()->for($owner, 'owner')->for($child)->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->pen()->create([
        'occurred_on' => today(),
        'category_id' => $child->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::MerchantRule,
        'merchant_rule_id' => $merchantRule->id,
    ]);
    $this->actingAs($owner)
        ->post(route('categories.archival.store', $parent))
        ->assertRedirect(route('categories.index'))
        ->assertSessionHasNoErrors();

    expect($parent->fresh()->archived_at)->not->toBeNull()
        ->and($child->fresh()->archived_at)->not->toBeNull()
        ->and($merchantRule->fresh()->enabled)->toBeFalse()
        ->and($transaction->fresh())
        ->category_id->toBe($child->id)
        ->merchant_rule_id->toBe($merchantRule->id);

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_options', [])
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.category.id', $child->id)
                ->where('selected_transaction.category.name', 'Dining')));

    $this->get(route('reports.show', [
        'currency' => 'PEN',
        'date_from' => today()->startOfMonth()->toDateString(),
    ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_groups.0.category.name', 'Food')
            ->where('category_groups.0.children.0.category.name', 'Dining')
            ->where('category_groups.0.children.0.category.archived', true));

    $otherTransaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->put(route('transactions.category.update', $otherTransaction), [
        'category_id' => $child->id,
    ])->assertSessionHasErrors('category_id');

    $this->post(route('merchant_rules.store'), [
        'merchant' => 'Archived target',
        'category_id' => $child->id,
        'transaction_kind' => null,
        'currency' => null,
        'enabled' => true,
    ])->assertSessionHasErrors('category_id');
});

test('an archived Category can be edited and unarchived without a revision contract', function () {
    $owner = User::factory()->create();
    $archived = Category::factory()->for($owner, 'owner')->archived()->create(['name' => 'Food']);
    $active = Category::factory()->for($owner, 'owner')->create(['name' => 'food']);

    $this->actingAs($owner)
        ->delete(route('categories.archival.destroy', $archived))
        ->assertSessionHasErrors('category');

    $this->patch(route('categories.update', $archived), [
        'name' => 'Groceries',
        'parent_id' => null,
    ])->assertSessionHasNoErrors();

    $this->delete(route('categories.archival.destroy', $archived))
        ->assertSessionHasNoErrors();

    expect($archived->fresh())
        ->id->toBe($archived->id)
        ->name->toBe('Groceries')
        ->archived_at->toBeNull()
        ->and($active->fresh())->not->toBeNull();
});

test('legacy Category revision deletion trash and restoration contracts are absent', function () {
    foreach (['revision', 'deletion_id', 'purge_after', 'deleted_at'] as $column) {
        expect(Schema::hasColumn('categories', $column))->toBeFalse();
    }

    expect(Schema::hasColumn('categories', 'archived_at'))->toBeTrue()
        ->and(Schema::hasTable('financial_data_tombstones'))->toBeFalse()
        ->and(Route::has('categories.destroy'))->toBeFalse()
        ->and(Route::has('categories.retirement.store'))->toBeFalse()
        ->and(Route::has('categories.retirement.destroy'))->toBeFalse()
        ->and(Route::has('trash.categories.restoration.store'))->toBeFalse()
        ->and(Route::has('categories.archival.store'))->toBeTrue()
        ->and(Route::has('categories.archival.destroy'))->toBeTrue();
});
