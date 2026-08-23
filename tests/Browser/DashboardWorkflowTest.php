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

test('secondary navigation keeps management destinations reachable', function () {
    $this->actingAs(User::factory()->create());

    foreach ([
        ['nav-statement-imports', 'Statement Imports', '/statement-imports'],
        ['nav-categories', 'Categories', '/categories'],
        ['nav-merchant-rules', 'Merchant Rules', '/merchant-rules'],
        ['nav-parser-profiles', 'Parser Profiles', '/parser-profiles'],
    ] as [$testId, $label, $path]) {
        visit('/')
            ->assertSeeIn('[data-test="'.$testId.'"]', $label)
            ->click('[data-test="'.$testId.'"]')
            ->assertPathIs($path)
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    }
});
