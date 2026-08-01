<?php

use App\Models\FinancialDataTombstone;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('changing the owner email invalidates other sessions', function () {
    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'another-device-session',
        'user_id' => $user->id,
        'payload' => 'payload',
        'last_activity' => now()->getTimestamp(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'new-owner@example.com',
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'another-device-session')->exists())->toBeFalse();
});

test('owner email changes require fresh authentication', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Changed Owner',
            'email' => 'changed@example.com',
        ])
        ->assertRedirect(route('password.confirm'));

    expect($user->refresh()->email)->not->toBe('changed@example.com');
});

test('permanent deletion requires fresh passkey authentication', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'))
        ->assertRedirect('/confirm-passkey');

    expect($user->fresh())->not->toBeNull();
});

test('user can delete their account after fresh passkey authentication', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession(['auth.passkey_confirmed_at' => time()])
        ->delete(route('profile.destroy'));

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('account deletion cannot bypass financial retention and tombstone rules', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user, 'owner')->create();

    $this->actingAs($user)
        ->withSession(['auth.passkey_confirmed_at' => time()])
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors('account');

    expect($user->fresh())->not->toBeNull()
        ->and($transaction->fresh())->not->toBeNull();
});

test('an account can be deleted after retained payloads have become append-only tombstones', function () {
    $user = User::factory()->create();
    $tombstone = FinancialDataTombstone::query()->create([
        'id' => '01983d79-a780-72f0-bb34-9b4f3f0cf399',
        'owner_id' => $user->id,
        'resource_type' => 'category',
        'resource_id' => 99,
        'deleted_at' => now()->subDays(31),
        'purged_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.passkey_confirmed_at' => time()])
        ->delete(route('profile.destroy'))
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull()
        ->and($tombstone->fresh())->not->toBeNull();
});

test('expired passkey authentication cannot delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession(['auth.passkey_confirmed_at' => now()->subSeconds(901)->getTimestamp()])
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'));

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/confirm-passkey');

    expect($user->fresh())->not->toBeNull();
});
