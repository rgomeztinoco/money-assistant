<?php

use App\Actions\Retention\PurgeExpiredFinancialData;
use App\Http\Middleware\RequirePasskeyConfirmation;
use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\FinancialDataTombstone;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('an eligible explicit deletion moves its Category into recoverable trash for thirty days', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Temporary Category',
    ]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertRedirect(route('categories.index'));

    expect(Category::find($category->id))->toBeNull();

    $trashedCategory = Category::onlyTrashed()->findOrFail($category->id);

    expect($trashedCategory)
        ->name->toBe('Temporary Category')
        ->deleted_at->toEqual(Date::now())
        ->purge_after->toEqual(Date::now()->addDays(30))
        ->deletion_id->not->toBeNull();
});

test('an owner can restore a deleted Category with its identity from the Categories page', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Recover Me',
    ]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1]);

    $trashedCategory = Category::onlyTrashed()->findOrFail($category->id);

    $this->post(route('categories.store'), [
        'name' => 'Recover Me',
        'parent_id' => null,
    ])->assertSessionHasErrors('name');

    Date::setTestNow(Date::now()->addDays(29));

    $this->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('trashed_categories.0.deletion_id', $trashedCategory->deletion_id)
            ->where('trashed_categories.0.name', 'Recover Me')
            ->where('trashed_categories.0.purge_after', $trashedCategory->purge_after->toIso8601String()));

    $this->post(route('trash.categories.restoration.store', $trashedCategory->deletion_id))
        ->assertRedirect();

    $restoredCategory = Category::query()->findOrFail($category->id);

    expect($restoredCategory)
        ->id->toBe($category->id)
        ->name->toBe('Recover Me')
        ->deleted_at->toBeNull()
        ->purge_after->toBeNull()
        ->deletion_id->toBeNull();
});

test('purge removes an expired payload and leaves only a payload-free tombstone', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Prohibited Tombstone Name',
    ]);
    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1]);

    $deletedCategory = Category::onlyTrashed()->findOrFail($category->id);
    $deletionId = $deletedCategory->deletion_id;
    $deletedAt = $deletedCategory->deleted_at;

    Date::setTestNow(Date::now()->addDays(30));

    expect(app(PurgeExpiredFinancialData::class)->handle())->toBe(1)
        ->and(Category::withTrashed()->find($category->id))->toBeNull();

    $tombstone = FinancialDataTombstone::query()->sole();
    $serializedTombstone = json_encode($tombstone->getAttributes(), JSON_THROW_ON_ERROR);

    expect($tombstone)
        ->id->toBe($deletionId)
        ->owner_id->toBe($owner->id)
        ->resource_type->toBe('category')
        ->resource_id->toBe($category->id)
        ->deleted_at->toEqual($deletedAt)
        ->purged_at->toEqual(Date::now())
        ->and($serializedTombstone)
        ->not->toContain('Prohibited Tombstone Name');
});

test('a Category referenced by another protected financial resource cannot enter trash', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    CategoryTarget::factory()->for($owner, 'owner')->for($category)->create();

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertSessionHasErrors('category');

    expect($category->fresh()->deleted_at)->toBeNull()
        ->and(Category::onlyTrashed()->count())->toBe(0);
});

test('expired financial trash is scheduled for automatic purge', function () {
    expect(collect(Schedule::events())->contains(
        fn ($event): bool => $event->description === 'expired-financial-data-purge',
    ))->toBeTrue();
});

test('financial data tombstones are database-enforced append-only records', function (string $operation) {
    $tombstone = FinancialDataTombstone::query()->create([
        'id' => Str::uuid()->toString(),
        'owner_id' => 1,
        'resource_type' => 'category',
        'resource_id' => 123,
        'deleted_at' => now()->subDays(30),
        'purged_at' => now(),
    ]);

    expect(fn () => DB::transaction(function () use ($operation, $tombstone): void {
        if ($operation === 'update') {
            FinancialDataTombstone::query()
                ->whereKey($tombstone->id)
                ->update(['resource_id' => 456]);

            return;
        }

        FinancialDataTombstone::query()->whereKey($tombstone->id)->delete();
    }))->toThrow(QueryException::class);
})->with(['update', 'delete']);
