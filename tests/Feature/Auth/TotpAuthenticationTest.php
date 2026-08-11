<?php

use App\Models\User;

test('TOTP authentication is unavailable', function () {
    $owner = User::factory()->create();

    $this->get('/two-factor-challenge')->assertNotFound();
    $this->post('/two-factor-challenge')->assertNotFound();

    $this->actingAs($owner)->post('/user/two-factor-authentication')->assertNotFound();
    $this->actingAs($owner)->delete('/user/two-factor-authentication')->assertNotFound();
    $this->actingAs($owner)->post('/user/confirmed-two-factor-authentication')->assertNotFound();
    $this->actingAs($owner)->get('/user/two-factor-qr-code')->assertNotFound();
    $this->actingAs($owner)->get('/user/two-factor-recovery-codes')->assertNotFound();
    $this->actingAs($owner)->post('/user/two-factor-recovery-codes')->assertNotFound();
    $this->actingAs($owner)->get('/user/two-factor-secret-key')->assertNotFound();
});
