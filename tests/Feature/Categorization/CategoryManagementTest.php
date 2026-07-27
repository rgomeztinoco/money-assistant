<?php

use App\CategoryAssignmentProvenance;
use App\Http\Middleware\RequirePasskeyConfirmation;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can read create and edit the two-level taxonomy', function () {
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
            ->where('categories.0.revision', 1));

    $this->post(route('categories.store'), [
        'name' => '  Local   Transport ',
        'parent_id' => $transport->id,
        'description' => 'Trips taken after arrival.',
        'examples' => ['Bus', ' Taxi ', '0'],
    ])->assertRedirect(route('categories.index'));

    $category = Category::query()->where('name', 'Local Transport')->sole();

    expect($category->parent_id)->toBe($transport->id)
        ->and($category->description)->toBe('Trips taken after arrival.')
        ->and($category->examples)->toBe(['Bus', 'Taxi', '0']);

    $this->patch(route('categories.update', $category), [
        'expected_revision' => 1,
        'name' => 'Local Transit',
        'parent_id' => $food->id,
        'description' => '',
        'examples' => [],
    ])->assertRedirect(route('categories.index'));

    expect($category->fresh())
        ->id->toBe($category->id)
        ->name->toBe('Local Transit')
        ->parent_id->toBe($food->id)
        ->description->toBeNull()
        ->revision->toBe(2);
});

test('active Category names are unique among siblings but reusable under another parent', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $pets = Category::factory()->for($owner, 'owner')->create(['name' => 'Pets']);
    Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Dining']);

    $this->actingAs($owner)
        ->post(route('categories.store'), [
            'name' => ' dining ',
            'parent_id' => $food->id,
            'examples' => [],
        ])
        ->assertSessionHasErrors('name');

    $this->post(route('categories.store'), [
        'name' => 'Dining',
        'parent_id' => $pets->id,
        'examples' => [],
    ])->assertSessionHasNoErrors();

    expect(Category::query()->where('name', 'Dining')->count())->toBe(2);
});

test('renaming and moving a Category preserves its identity and updates historical reporting labels', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $category = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Cafes',
    ]);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)->patch(route('categories.update', $category), [
        'expected_revision' => 1,
        'name' => 'Coffee Shops',
        'parent_id' => $travel->id,
        'description' => null,
        'examples' => [],
    ])->assertSessionHasNoErrors();

    expect($transaction->fresh()->category_id)->toBe($category->id);

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.category.id', $category->id)
            ->where('selected_transaction.category.name', 'Coffee Shops')
            ->where('category_totals.0.category.name', 'Coffee Shops')
            ->where('category_totals.1.category.name', 'Travel'));
});

test('retirement enforces blockers and referenced Categories cannot be permanently deleted', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create([
        'name' => 'Dining',
    ]);
    Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $child->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)
        ->post(route('categories.retirement.store', $parent), ['expected_revision' => 1])
        ->assertSessionHasErrors('category');

    $this->post(route('categories.retirement.store', $child), ['expected_revision' => 1])
        ->assertSessionHasNoErrors();

    $this->post(route('categories.retirement.store', $parent), ['expected_revision' => 1])
        ->assertSessionHasNoErrors();

    expect($child->fresh()->retired_at)->not->toBeNull()
        ->and($parent->fresh()->retired_at)->not->toBeNull();

    $this->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $child), ['expected_revision' => 2])
        ->assertSessionHasErrors('category');

    $this->assertModelExists($child);
});

test('only a never-referenced Category can be deleted after fresh passkey authentication', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertRedirect(route('passkey.confirmation'));

    $this->assertModelExists($category);

    $this->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertRedirect(route('categories.index'));

    $this->assertModelMissing($category);
});

test('a Category remains historically referenced after a Transaction returns to Uncategorized', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    $this->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 2,
        'category_id' => null,
    ])->assertSessionHasNoErrors();

    $this->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertSessionHasErrors('category');

    $this->assertModelExists($category);
});

test('reactivation preserves identity and rejects active sibling conflicts', function () {
    $owner = User::factory()->create();
    $retired = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Food',
        'retired_at' => now(),
        'revision' => 2,
    ]);
    $active = Category::factory()->for($owner, 'owner')->create(['name' => 'food']);

    $this->actingAs($owner)
        ->delete(route('categories.retirement.destroy', $retired), ['expected_revision' => 2])
        ->assertSessionHasErrors('category');

    $active->delete();

    $this->delete(route('categories.retirement.destroy', $retired), ['expected_revision' => 2])
        ->assertSessionHasNoErrors();

    expect($retired->fresh())
        ->id->toBe($retired->id)
        ->retired_at->toBeNull()
        ->revision->toBe(3);
});

test('stale Category changes fail closed', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['revision' => 2]);

    $this->actingAs($owner)
        ->patch(route('categories.update', $category), [
            'expected_revision' => 1,
            'name' => 'Changed',
            'parent_id' => null,
            'description' => null,
            'examples' => [],
        ])
        ->assertSessionHasErrors('expected_revision');

    expect($category->fresh()->name)->not->toBe('Changed');
});
