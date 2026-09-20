<?php

use App\Actions\Breakdown\ReadBreakdown;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            ->where('categories.0.archived_at', null));

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

test('the Category management payload includes row counts and archive impact', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    Transaction::factory()->for($owner, 'owner')->for($food)->create();
    Transaction::factory()->count(2)->for($owner, 'owner')->for($dining)->create();
    MerchantRule::factory()->for($owner, 'owner')->for($food)->create();
    MerchantRule::factory()->for($owner, 'owner')->for($dining)->create();
    MerchantRule::factory()->for($owner, 'owner')->for($dining)->disabled()->create();

    $this->actingAs($owner)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.id', $food->id)
            ->where('categories.0.child_count', 1)
            ->where('categories.0.transaction_count', 1)
            ->where('categories.0.active_merchant_rule_count', 1)
            ->where('categories.0.archive_impact.active_child_count', 1)
            ->where('categories.0.archive_impact.active_merchant_rule_count', 2)
            ->where('categories.0.children.0.id', $dining->id)
            ->where('categories.0.children.0.child_count', 0)
            ->where('categories.0.children.0.transaction_count', 2)
            ->where('categories.0.children.0.active_merchant_rule_count', 1)
            ->where('categories.0.children.0.archive_impact.active_child_count', 0)
            ->where('categories.0.children.0.archive_impact.active_merchant_rule_count', 1));
});

test('Category search ignores case and accents and archived Categories stay hidden by default', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Cafés']);
    Category::factory()->for($owner, 'owner')->for($food, 'parent')->archived()->create(['name' => 'Coffee']);
    Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);

    $this->actingAs($owner)
        ->get(route('categories.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.archived', 'without')
            ->has('categories', 2)
            ->has('categories.0.children', 1)
            ->where('categories.0.children.0.name', 'Cafés'));

    $this->get(route('categories.index', ['search' => 'CAFES']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.search', 'CAFES')
            ->has('categories', 1)
            ->where('categories.0.name', 'Food')
            ->has('categories.0.children', 1)
            ->where('categories.0.children.0.name', 'Cafés'));

    $this->get(route('categories.index', ['archived' => 'only']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.archived', 'only')
            ->has('categories', 1)
            ->where('categories.0.name', 'Food')
            ->has('categories.0.children', 1)
            ->where('categories.0.children.0.name', 'Coffee'));
});

test('Category table sorting is temporary and preserves parent before child context', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $cafes = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Cafes']);
    $groceries = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Groceries']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    Transaction::factory()->for($owner, 'owner')->for($food)->create();
    Transaction::factory()->count(2)->for($owner, 'owner')->for($cafes)->create();
    Transaction::factory()->count(4)->for($owner, 'owner')->for($groceries)->create();
    Transaction::factory()->count(3)->for($owner, 'owner')->for($travel)->create();

    $this->actingAs($owner)
        ->get(route('categories.index', ['sort' => 'transactions', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'transactions')
            ->where('filters.direction', 'desc')
            ->where('categories.0.name', 'Travel')
            ->where('categories.1.name', 'Food')
            ->where('categories.1.children.0.name', 'Groceries')
            ->where('categories.1.children.1.name', 'Cafes'));

    expect(Category::query()->orderByRaw('lower(name)')->pluck('name')->all())
        ->toBe(['Cafes', 'Food', 'Groceries', 'Travel']);
});

test('Category management rejects unsupported filter values', function (array $query, string $field) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('categories.index', $query))
        ->assertSessionHasErrors($field);
})->with([
    'search longer than 255 characters' => [['search' => str_repeat('a', 256)], 'search'],
    'unknown archive mode' => [['archived' => 'sometimes'], 'archived'],
    'unknown sort column' => [['sort' => 'created_at'], 'sort'],
    'unknown sort direction' => [['direction' => 'sideways'], 'direction'],
]);

test('inline Category creation returns the new option to the originating workflow', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);

    $response = $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(route('categories.inline.store'), [
            'name' => '  Dining   out ',
            'parent_id' => $food->id,
        ]);

    $category = Category::query()->where('name', 'Dining out')->sole();

    $response
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('created_category', [
            'id' => $category->id,
            'name' => 'Dining out',
            'parent_id' => $food->id,
            'path' => 'Food > Dining out',
        ]);
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

    $report = app(ReadBreakdown::class)->handle(
        owner: $owner,
        filters: [
            'currency' => Currency::Pen->value,
            'period' => 'custom',
            'date_from' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'date_to' => CarbonImmutable::today()->toDateString(),
        ],
    );

    expect($report['category_groups'][0]['category']['name'])->toBe('Travel')
        ->and($report['category_groups'][0]['children'][0]['category']['name'])->toBe('Coffee Shops');
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

    $report = app(ReadBreakdown::class)->handle(
        owner: $owner,
        filters: [
            'currency' => Currency::Pen->value,
            'period' => 'custom',
            'date_from' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'date_to' => CarbonImmutable::today()->toDateString(),
        ],
    );

    expect($report['category_groups'][0]['category']['name'])->toBe('Food')
        ->and($report['category_groups'][0]['children'][0]['category']['name'])->toBe('Dining')
        ->and($report['category_groups'][0]['children'][0]['amount_minor']['PEN'])->toBe((string) $transaction->amount_minor);

    $otherTransaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->put(route('transactions.category.update', $otherTransaction), [
        'category_id' => $child->id,
    ])->assertSessionHasErrors('category_id');

    $this->put(route('breakdown.transactions.classification.update', $otherTransaction), [
        'category_id' => $child->id,
        'apply_to_matching' => true,
    ])->assertSessionHasErrors('category_id');
});

test('an archived Category can be edited and unarchived', function () {
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
