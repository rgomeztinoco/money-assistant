<?php

use App\Models\User;
use Illuminate\Database\QueryException;

test('public registration is unavailable', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

test('only one Owner Account can exist', function () {
    User::factory()->create();

    expect(fn () => User::factory()->create())
        ->toThrow(QueryException::class);
});
