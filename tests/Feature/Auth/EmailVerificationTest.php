<?php

use App\Models\User;

test('email verification is unavailable', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get('/email/verify')->assertNotFound();
    $this->actingAs($owner)
        ->get("/email/verify/{$owner->id}/".sha1($owner->email))
        ->assertNotFound();
    $this->actingAs($owner)->post('/email/verification-notification')->assertNotFound();
});
