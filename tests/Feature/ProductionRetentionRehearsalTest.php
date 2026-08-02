<?php

use App\Models\Category;
use App\Models\FinancialDataTombstone;
use App\Models\User;

test('the production retention rehearsal purges its payload and leaves an append-only tombstone', function (string $gate) {
    $owner = User::factory()->create();

    $this->artisan('app:rehearse-production-retention', ['gate' => $gate])
        ->expectsOutputToContain("PRODUCTION_TRUST_EVIDENCE gate={$gate} outcome=passed")
        ->assertSuccessful();

    expect(Category::withTrashed()->count())->toBe(0)
        ->and(FinancialDataTombstone::query()->count())->toBe(0)
        ->and($owner->fresh())->not->toBeNull();
})->with(['retention-purge', 'audit-tombstone']);
