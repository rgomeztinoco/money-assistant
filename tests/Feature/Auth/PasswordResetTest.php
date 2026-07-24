<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('email password reset is unavailable', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->get('/forgot-password')->assertNotFound();
    $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();
    $this->get('/reset-password/token')->assertNotFound();
    $this->post('/reset-password', [
        'token' => 'token',
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertNotFound();

    Notification::assertNotSentTo($user, ResetPassword::class);
});
