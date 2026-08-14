<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile page is displayed', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertOk();
});

test('profile information can be updated', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('profile.update'), [
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($owner->refresh())
        ->name->toBe('Test Owner')
        ->email->toBe('owner@example.com');
});

test('changing the owner email invalidates other sessions', function () {
    $owner = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'another-device-session',
        'user_id' => $owner->id,
        'payload' => 'payload',
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('profile.update'), [
            'name' => $owner->name,
            'email' => 'new-owner@example.com',
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'another-device-session')->exists())->toBeFalse();
});

test('owner email changes require fresh authentication', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->patch(route('profile.update'), [
            'name' => 'Changed Owner',
            'email' => 'changed@example.com',
        ])
        ->assertRedirect(route('password.confirm'));

    expect($owner->refresh()->email)->not->toBe('changed@example.com');
});
