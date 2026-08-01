<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot visit Insights', function () {
    $this->get(route('insights.index'))->assertRedirect(route('login'));
});

test('the owner can visit the detailed Insights destination', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('insights.index', [
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-18',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/index'));
});
