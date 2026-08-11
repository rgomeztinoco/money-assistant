<?php

use App\Models\User;

test('the root URL directs unauthenticated visitors to login', function () {
    $this->get(route('home'))
        ->assertRedirectToRoute('login');
});

test('the root URL directs the authenticated owner to the dashboard', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertRedirectToRoute('dashboard');
});
