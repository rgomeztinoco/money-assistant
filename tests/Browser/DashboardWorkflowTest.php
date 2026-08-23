<?php

use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the account menu retains Settings outside the permanent product navigation', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('[data-test="sidebar-menu-button"]')
        ->assertSee('Settings')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Reports')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
