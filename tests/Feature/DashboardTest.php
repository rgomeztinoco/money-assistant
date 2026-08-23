<?php

use App\Models\User;

test('the removed Dashboard path is not a duplicate Home destination', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertNotFound();
});
