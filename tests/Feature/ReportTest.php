<?php

use App\Models\User;

test('the removed Reports paths are not duplicate Trends destinations', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertNotFound();
})->with(['/reports/PEN', '/reports/USD']);
