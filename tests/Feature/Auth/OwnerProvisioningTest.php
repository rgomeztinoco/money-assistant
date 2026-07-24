<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('an operator can provision the Owner Account', function () {
    $this->artisan('owner:create')
        ->expectsQuestion('Owner name', 'Ricardo')
        ->expectsQuestion('Owner email', 'owner@example.com')
        ->expectsQuestion('Recovery password', 'a-secure-recovery-password')
        ->expectsOutput('Owner Account created. Register a passkey after signing in.')
        ->assertSuccessful();

    $owner = User::query()->sole();

    expect($owner->name)->toBe('Ricardo')
        ->and($owner->email)->toBe('owner@example.com')
        ->and(Hash::check('a-secure-recovery-password', $owner->password))->toBeTrue();
});

test('an operator cannot provision a second Owner Account', function () {
    User::factory()->create();

    $this->artisan('owner:create')
        ->expectsOutput('An Owner Account already exists.')
        ->assertFailed();
});
