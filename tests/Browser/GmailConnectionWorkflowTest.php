<?php

use App\Models\GmailConnection;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner sees Gmail connection health without credentials reaching the page', function () {
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'access_token' => 'browser-hidden-access-token',
        'refresh_token' => 'browser-hidden-refresh-token',
    ]);
    $this->actingAs($connection->owner);

    $page = visit(route('connections.edit'));

    $page
        ->assertSee('Gmail')
        ->assertSee('Healthy')
        ->assertSee('receipts@example.test')
        ->assertSee('Read-only Gmail access')
        ->assertDontSee('browser-hidden-access-token')
        ->assertDontSee('browser-hidden-refresh-token')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
