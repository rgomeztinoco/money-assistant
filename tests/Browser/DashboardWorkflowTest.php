<?php

use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the account menu retains Settings outside the permanent product navigation', function () {
    $this->actingAs(User::factory()->create(['name' => '  élena   山田  ']));

    visit('/')
        ->assertSeeIn('[data-slot="avatar-fallback"]', 'É山')
        ->click('[data-test="sidebar-menu-button"]')
        ->assertSee('Settings')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Reports')
        ->click('Settings')
        ->assertPathIs('/settings/profile')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('secondary navigation keeps data sources and management destinations reachable', function () {
    $this->actingAs(User::factory()->create());

    foreach ([
        ['nav-home', 'Home', '/'],
        ['nav-breakdown', 'Breakdown', '/breakdown'],
        ['nav-trends', 'Trends', '/trends'],
    ] as [$testId, $label, $path]) {
        visit('/')
            ->assertSeeIn('[data-test="'.$testId.'"]', $label)
            ->click('[data-test="'.$testId.'"]')
            ->assertPathIs($path)
            ->assertAttribute('[data-test="'.$testId.'"]', 'data-active', 'true')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    }

    foreach ([
        ['nav-gmail', 'Gmail', '/data-sources/gmail'],
        ['nav-statement-imports', 'Statement Imports', '/statement-imports'],
        ['nav-categories', 'Categories', '/categories'],
        ['nav-merchant-rules', 'Merchant Rules', '/merchant-rules'],
    ] as [$testId, $label, $path]) {
        visit('/')
            ->assertSeeIn('[data-test="'.$testId.'"]', $label)
            ->click('[data-test="'.$testId.'"]')
            ->assertPathIs($path)
            ->assertAttribute('[data-test="'.$testId.'"]', 'data-active', 'true')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    }

    visit('/')
        ->assertDontSee('Parser Profiles')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
