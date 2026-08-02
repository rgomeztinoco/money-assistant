<?php

use App\Models\IntegrationIncident;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the manual replay rehearsal exercises parked work and leaves production state unchanged', function () {
    $owner = User::factory()->create();

    $this->artisan('app:rehearse-manual-replay')
        ->expectsOutputToContain('MANUAL_REPLAY_REHEARSAL outcome=passed')
        ->assertSuccessful();

    expect($owner->fresh())->not->toBeNull()
        ->and(Reminder::query()->count())->toBe(0)
        ->and(ReminderDelivery::query()->count())->toBe(0)
        ->and(IntegrationIncident::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});
