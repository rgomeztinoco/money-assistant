<?php

use App\Models\User;

test('the owner confirms once to create copy rotate and revoke agent tokens', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $page = visit('/settings/agent-access');
    $page->assertPathIs('/user/confirm-password')
        ->type('#password', 'password')->click('[data-test="confirm-password-button"]')
        ->assertPathIs('/settings/agent-access')
        ->type('#token-name', 'Codex laptop')->click('Create token')
        ->assertSee('Copy your token now')->assertSee('MONEY_ASSISTANT_TOKEN')
        ->screenshot(filename: 'agent-access-issued');
    $page->script('Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: async (text) => { window.copiedToken = text; } } })');
    $page->click('Copy token')->assertSee('Copied');
    expect($page->script('window.copiedToken'))->toMatch('/^\d+\|/');
    $page->click('OpenClaw')->assertSee('streamable-http')->assertSee('must not be committed')
        ->click('Generic MCP')->assertSee('Authorization: Bearer <token>')
        ->click('Done')->assertDontSee('Copy your token now')
        ->click('Rotate')->assertSee('Rotate Codex laptop')
        ->click('Rotate token')->assertSee('Copy your token now')->click('Done')
        ->click('Revoke')->assertSee('Revoke Codex laptop')
        ->click('Revoke token')->assertSee('No agent tokens yet')
        ->assertNoJavaScriptErrors();
});
