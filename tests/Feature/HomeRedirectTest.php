<?php

use App\Models\User;

test('the root URL directs unauthenticated visitors to login', function () {
    $this->get(route('home'))
        ->assertRedirectToRoute('login');
});

test('the root URL is the authenticated owner Home', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertOk();
});
