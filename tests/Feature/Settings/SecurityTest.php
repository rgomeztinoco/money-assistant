<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('security page displays passkey and recovery password settings', function () {
    Features::passkeys([
        'confirmPassword' => true,
    ]);

    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManagePasskeys', true)
            ->where('passkeys', []),
        );
});

test('security page sends passkey instants for browser-relative presentation', function () {
    Features::passkeys([
        'confirmPassword' => true,
    ]);

    $owner = User::factory()->create();
    $passkey = $owner->passkeys()->create([
        'name' => 'Laptop passkey',
        'credential_id' => 'security-page-credential',
        'credential' => [],
    ]);
    $passkey->forceFill([
        'created_at' => '2026-09-18 12:00:00 UTC',
        'last_used_at' => '2026-09-18 13:30:00 UTC',
    ])->save();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('passkeys.0.created_at', '2026-09-18T12:00:00+00:00')
            ->where('passkeys.0.last_used_at', '2026-09-18T13:30:00+00:00')
            ->missing('passkeys.0.created_at_diff')
            ->missing('passkeys.0.last_used_at_diff'),
        );
});

test('security page requires fresh authentication', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('security page renders without passkeys when the feature is disabled', function () {
    config(['fortify.features' => []]);

    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('canManagePasskeys', false)
            ->where('passkeys', []),
        );
});

test('recovery password can be updated', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $owner->refresh()->password))->toBeTrue();
});

test('updating the recovery password invalidates other sessions', function () {
    $owner = User::factory()->create();
    $rememberToken = $owner->remember_token;

    DB::table('sessions')->insert([
        'id' => 'another-device-session',
        'user_id' => $owner->id,
        'payload' => 'payload',
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $this->assertAuthenticatedAs($owner);

    expect(DB::table('sessions')->where('id', 'another-device-session')->exists())->toBeFalse()
        ->and($owner->refresh()->remember_token)->not->toBe($rememberToken);
});

test('recovery password updates require fresh authentication', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.confirm'));

    expect(Hash::check('password', $owner->refresh()->password))->toBeTrue();
});
