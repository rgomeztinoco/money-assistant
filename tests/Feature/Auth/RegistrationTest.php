<?php

use App\Models\User;

test('public registration is unavailable', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

test('a second Owner Account cannot be provisioned', function () {
    User::factory()->create();

    $this->artisan('owner:create')
        ->expectsOutput('An Owner Account already exists.')
        ->assertFailed();
});
